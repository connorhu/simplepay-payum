<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePay\Exception\RequestException;
use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\PaymentAlreadySettledException;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;

/**
 * Elindítja a fizetést és átirányít a SimplePay fizetőoldalára.
 *
 * Automatikus újrapróbálás SEHOL nincs: egy vak `/start` ismétlés dupla
 * terhelést okozhatna a vásárló kártyáján. A hálózati és protokoll-hibák
 * változatlanul továbbdobódnak.
 */
final class CaptureAction implements ActionInterface, ApiAwareInterface
{
    use SimplePayApiAware;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /**
     * Az `instanceof` őrök nem az `assertSupports()`-ot ismétlik meg —
     * azok adják a statikus elemzőnek a típusszűkítést, amit egy típus-
     * annotáció csak állítana, nem bizonyítana.
     */
    public function execute($request): void
    {
        if (!$request instanceof Capture) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $model = $request->getModel();

        if (!$model instanceof \ArrayAccess) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $details = Details::fromModel($model);
        $state = $details->state();

        // Lezárt fizetésre soha nem indítunk új tranzakciót: az isLive() csak
        // azt mondja meg, van-e MÉG élő fizetőoldal, arról nem szól, hogy a
        // fizetés korábban már lezárult-e. A FINISHED VÉGLEGES státusz, tehát
        // az isLive() alábbi őre előtt magában elbukna ezen az ágon — enélkül
        // a kód idáig sem jutna el, és egy kifizetett rendelésre is új
        // /start-ot indítana.
        if (null !== $state->status && self::isSettled($state->status)) {
            throw new PaymentAlreadySettledException(sprintf(
                'A fizetés már lezárult "%s" állapotban — új tranzakciót indítani rá dupla '
                . 'terhelést jelentene, és a meglévő tranzakció adatait is felülírná. '
                . 'Egy sikertelen kísérlet (CANCELLED, TIMEOUT, NOTAUTHORIZED, FRAUD) után '
                . 'az újrapróbálkozás megengedett, egy sikeres vagy jóváírt fizetés után nem.',
                $state->status->value,
            ));
        }

        // Újra-capture őr: ha a vevő visszalép és újraküld, ne indítsunk
        // második tranzakciót ugyanarra a fizetésre — irányítsuk vissza a
        // még élő fizetőoldalra. A régi implementáció itt új /start-ot
        // indított ugyanazzal az orderRef-fel.
        if ($state->isLive(new \DateTimeImmutable())) {
            $paymentUrl = $state->paymentUrl;

            if (null === $paymentUrl) {
                throw new \LogicException(
                    'Az isLive() igaznak találta a tranzakciót, de nincs fizetőoldala — '
                    . 'a TransactionState invariánsa sérült.',
                );
            }

            throw new HttpRedirect($paymentUrl);
        }

        $startData = $details->startData();

        try {
            $response = $this->api()->client->start($startData->toStartRequest());
        } catch (RequestException $exception) {
            $details->writeState($state->with(lastErrorCodes: $exception->codes()));

            throw $exception;
        }

        $details->writeState(new TransactionState(
            transactionId: $response->transactionId,
            status: TransactionStatus::Init,
            paymentUrl: $response->paymentUrl,
            timeout: $response->timeout,
            attempt: $startData->attempt,
            // Az IPN-napló egy korábbi, lejárt próbálkozásból is értékes:
            // megmutatja, hogy akkor mi történt. Nem dobjuk el.
            ipnLog: $state->ipnLog,
        ));

        throw new HttpRedirect($response->paymentUrl);
    }

    public function supports($request): bool
    {
        return $request instanceof Capture && $request->getModel() instanceof \ArrayAccess;
    }

    /**
     * Az a három állapot, ami után pénz mozgott: ezekre soha nem indítunk új
     * tranzakciót. A többi végleges állapot (CANCELLED, TIMEOUT, NOTAUTHORIZED,
     * FRAUD) halott kísérlet, arra az újrapróbálkozás jogos.
     */
    private static function isSettled(TransactionStatus $status): bool
    {
        return TransactionStatus::Finished === $status ||
            TransactionStatus::Refund === $status ||
            TransactionStatus::Reversed === $status;
    }
}
