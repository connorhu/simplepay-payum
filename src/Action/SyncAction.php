<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePay\Request\QueryRequest;
use CodeConjure\SimplePay\Response\Transaction;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\TransactionNotFoundException;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SimplePayPayum\TransitionDecision;
use CodeConjure\SimplePayPayum\TransitionGuard;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Sync;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Lekérdezi a tranzakció valódi állapotát a SimplePay-től.
 *
 * ITT, ÉS CSAK ITT történik hálózati hívás státuszolvasáskor. A `StatusAction`
 * tiszta olvasás; a régi implementáció összemosta a kettőt, és minden
 * státuszolvasásnál lekérdezést indított.
 *
 * A protokoll-csomag minden lekérdezésnél `detailed: true`-t küld — nem
 * választható. Ennek adatvédelmi ára van: a válasz a vevő nevét, e-mail
 * címét és számlázási címét is tartalmazza, még ha a `Transaction` ezeket
 * el is dobja. Ne kapcsolj be HTTP-szintű törzs-naplózást éles környezetben.
 */
final class SyncAction implements ActionInterface, ApiAwareInterface
{
    use SimplePayApiAware;

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
        if (!$request instanceof Sync) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $model = $request->getModel();

        if (!$model instanceof \ArrayAccess) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $details = Details::fromModel($model);
        $state = $details->state();

        $transactionId = $state->transactionId;
        $orderRef = null;

        if (null === $transactionId) {
            // Nincs tranzakció-azonosítónk: a hivatkozás az egyetlen fogódzó.
            // Ha az sincs, a startData() hangos hibát dob — egy sosem
            // előkészített fizetést nincs mihez szinkronizálni.
            $orderRef = $details->startData()->orderRef;
        }

        $response = $this->api()->client->query(new QueryRequest(
            transactionIds: null === $transactionId ? [] : [$transactionId],
            orderRefs: null === $orderRef ? [] : [$orderRef],
        ));

        $transaction = null === $orderRef ? $response->first() : $response->byOrderRef($orderRef);

        if (null === $transaction) {
            throw new TransactionNotFoundException(sprintf(
                'A SimplePay nem ismer tranzakciót erre az azonosítóra: "%s".',
                $transactionId ?? $orderRef,
            ));
        }

        $details->writeState($this->merge($details, $transaction));
    }

    public function supports($request): bool
    {
        return $request instanceof Sync && $request->getModel() instanceof \ArrayAccess;
    }

    private function merge(Details $details, Transaction $transaction): TransactionState
    {
        $state = $details->state();
        $decision = $this->transitionGuard->decide($state->status, $transaction->status);

        $transactionId = $transaction->transactionId;
        $lastCheckedAt = new \DateTimeImmutable();
        $remainingTotal = $transaction->remainingTotal?->minorUnits;

        if (TransitionDecision::Apply !== $decision) {
            if (TransitionDecision::Rejected === $decision) {
                $this->logger->error('A SimplePay lekérdezés státusza nem illeszkedik a tárolt állapothoz; nem alkalmaztuk.', [
                    'event' => 'simplepay.sync.rejected',
                    'transaction_id' => $transaction->transactionId,
                    'stored_status' => $state->status?->value,
                    'queried_status' => $transaction->status->value,
                ]);
            }

            return $state->with(
                transactionId: $transactionId,
                lastCheckedAt: $lastCheckedAt,
                remainingTotal: $remainingTotal,
            );
        }

        return $state->with(
            transactionId: $transactionId,
            status: $transaction->status,
            lastCheckedAt: $lastCheckedAt,
            method: $transaction->method,
            paymentDate: $transaction->paymentDate,
            finishDate: $transaction->finishDate,
            remainingTotal: $remainingTotal,
        );
    }
}
