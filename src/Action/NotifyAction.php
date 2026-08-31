<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePay\Ipn\IpnMessage;
use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\MissingSignatureHeaderException;
use CodeConjure\SimplePayPayum\Model\IpnOutcome;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SimplePayPayum\Request\GetHttpRequestWithHeaders;
use CodeConjure\SimplePayPayum\TransitionDecision;
use CodeConjure\SimplePayPayum\TransitionGuard;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Feldolgozza a SimplePay értesítését (IPN), és aláírt visszaigazolást ad.
 *
 * A visszaigazolás MINDIG elmegy, ha az aláírás és a merchant stimmel — akkor
 * is, ha az állapotgépünk nem tud mit kezdeni a beérkezett státusszal. A
 * SimplePay addig ismétli az értesítést, amíg meg nem kapja az aláírt választ;
 * egy hitelesített üzenet vissza nem igazolása állapotgép-nézeteltérésből
 * végtelen ismétlési ciklust csinál. A régi implementáció itt kivételt dobott
 * és semmit nem válaszolt.
 *
 * Visszaigazolás NEM megy érvénytelen aláírásra és idegen merchantra —
 * mindkettő kivétel, amit a hívó controller 400-as válasszá alakít.
 *
 * A `HttpResponse` egy `ReplyInterface`, amit `throw`-val hordunk ki — tehát
 * az állapot mentése a `dobás` ágon hagyja el ezt a metódust. A hívónak a
 * kapott kivétel elkapása UTÁN kell perzisztálnia a modellt.
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use SimplePayApiAware;
    use GatewayAwareTrait;

    public function __construct(
        private readonly TransitionGuard $transitionGuard = new TransitionGuard(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->apiClass = Api::class;
    }

    /**
     * Az `instanceof` őrök nem az `assertSupports()`-ot ismétlik meg —
     * azok adják a statikus elemzőnek a típusszűkítést, amit egy típus-
     * annotáció csak állítana, nem bizonyítana.
     */
    public function execute($request): void
    {
        if (!$request instanceof Notify) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $model = $request->getModel();

        if (!$model instanceof \ArrayAccess) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $details = Details::fromModel($model);

        $httpRequest = new GetHttpRequestWithHeaders();
        $this->gateway->execute($httpRequest);

        $confirmation = $this->api()->client->ipn(
            $httpRequest->content,
            $this->signatureHeader($httpRequest),
        );

        $message = $confirmation->message();
        $state = $details->state();
        $decision = $this->transitionGuard->decide($state->status, $message->status);

        $outcome = match ($decision) {
            TransitionDecision::Apply => IpnOutcome::Applied,
            TransitionDecision::Duplicate => IpnOutcome::Duplicate,
            TransitionDecision::Rejected => IpnOutcome::Rejected,
        };

        $updated = TransitionDecision::Apply === $decision
            ? $this->applied($state, $message)
            : $state;

        $updated = $updated->withIpnEntry(
            new \DateTimeImmutable(),
            $message->transactionId,
            $message->status,
            $outcome,
        );

        $details->writeState($updated);

        $this->log($outcome, $message, $state->status, $updated);

        throw new HttpResponse(
            $confirmation->responseBody(),
            200,
            ['Signature' => $confirmation->responseSignature()],
        );
    }

    public function supports($request): bool
    {
        return $request instanceof Notify && $request->getModel() instanceof \ArrayAccess;
    }

    private function applied(TransactionState $state, IpnMessage $message): TransactionState
    {
        return $state->with(
            transactionId: $message->transactionId,
            status: $message->status,
            method: $message->method,
            paymentDate: $message->paymentDate,
            finishDate: $message->finishDate,
        );
    }

    /**
     * A Symfony-híd kisbetűs kulcsokkal adja a fejléceket, de más hidak
     * nem feltétlenül — ezért kisbetűsítve keresünk. Hiányzó vagy üres
     * érték hangos hiba: aláírás nélkül a törzs hitelesíthetetlen, tehát
     * nincs mit feldolgozni. Alapértelmezés nincs.
     */
    private function signatureHeader(GetHttpRequestWithHeaders $httpRequest): string
    {
        foreach ($httpRequest->headers as $name => $values) {
            if ('signature' !== strtolower($name)) {
                continue;
            }

            foreach ($values as $value) {
                if (is_string($value) && '' !== trim($value)) {
                    return $value;
                }
            }
        }

        throw new MissingSignatureHeaderException(
            'A SimplePay értesítésből hiányzik vagy üres a "Signature" fejléc. '
            . 'Aláírás nélkül a törzs nem hitelesíthető. Ha ez Symfonyn kívüli '
            . 'környezetben történt: a Payum PlainPhp hídja nem tölti a fejléceket, '
            . 'az IPN feldolgozása Symfony-hidat igényel.',
        );
    }

    private function log(
        IpnOutcome $outcome,
        IpnMessage $message,
        ?TransactionStatus $previousStatus,
        TransactionState $updatedState,
    ): void {
        $context = [
            'event' => 'simplepay.ipn.processed',
            'outcome' => $outcome->value,
            // A merchant a naplóban akkor válik hasznossá, ha egy alkalmazás
            // több SimplePay merchant-fiókot is használ (pl. pénznemenként
            // egyet) — enélkül a naplóból nem derülne ki, melyik fiókra
            // érkezett az értesítés.
            'merchant' => $this->api()->merchant,
            'transaction_id' => $message->transactionId,
            'order_ref' => $message->orderRef,
            'previous_status' => $previousStatus?->value,
            'incoming_status' => $message->status->value,
            'repeat_count' => $updatedState->ipnLog[count($updatedState->ipnLog) - 1]->repeatCount,
        ];

        match ($outcome) {
            IpnOutcome::Applied => $this->logger->info('SimplePay értesítés feldolgozva.', $context),
            // A duplikátum warning, nem notice: a SimplePay addig ismétel,
            // amíg el nem fogadja a visszaigazolásunkat. Egy növekvő
            // repeat_count azt jelenti, hogy NEM fogadja el — és ez az
            // egyetlen jelzésünk erre.
            IpnOutcome::Duplicate => $this->logger->warning(
                'SimplePay értesítés ismétlődött — lehet, hogy a visszaigazolásunkat nem fogadták el.',
                $context,
            ),
            IpnOutcome::Rejected => $this->logger->error(
                'SimplePay értesítés státusza nem illeszkedik a tárolt állapothoz; nem alkalmaztuk.',
                $context,
            ),
        };
    }
}
