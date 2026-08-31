<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePay\Money;
use CodeConjure\SimplePay\Request\RefundRequest;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Details;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Refund;

/**
 * Jóváírást indít.
 *
 * A Payum `Refund` requestnek nincs összeg mezője, ezért a hívó a
 * `simplepay_refund.amount` kulcsba írja a szándékot — a pénznem valódi
 * alegységében. Hiányzó összeg hangos hiba: NINCS olyan alapértelmezés,
 * hogy „akkor a teljes összeg". Egy jóváírás összegét nem találjuk ki.
 *
 * A státuszt ez az action NEM változtatja meg. Hogy a tranzakció valóban
 * `REFUND` állapotba került-e, azt az IPN vagy egy `Sync` dönti el — a
 * jóváírás elindítása és a jóváírás beállása két különböző esemény.
 */
final class RefundAction implements ActionInterface, ApiAwareInterface
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
        if (!$request instanceof Refund) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $model = $request->getModel();

        if (!$model instanceof \ArrayAccess) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $details = Details::fromModel($model);

        $amount = $details->refundAmount();
        $state = $details->state();

        // Nincs tranzakció-azonosítónk: a hivatkozás az egyetlen fogódzó.
        // Ha az sincs, a startData() hangos hibát dob — egy sosem
        // elindított fizetést nem lehet jóváírni.
        $orderRef = null === $state->transactionId ? $details->startData()->orderRef : null;

        $response = $this->api()->client->refund(new RefundRequest(
            refundTotal: Money::fromMinorUnits($amount, $this->api()->currency),
            orderRef: $orderRef,
            transactionId: $state->transactionId,
        ));

        $details->writeState($state->with(
            refundTransactionId: $response->refundTransactionId,
            refundTotal: $response->refundTotal->minorUnits,
            remainingTotal: $response->remainingTotal->minorUnits,
        ));

        // A simplepay_refund névtér egyszer elfogyasztandó parancs, nem
        // tartós állapot: sikeres /refund után töröljük, különben egy
        // ismételt Refund dispatch (retry, dupla kattintás) ugyanazt az
        // összeget olvasná ki újra, és egy második, valódi jóváírást
        // indítana. Csak a sikeres ágon töröljük — egy sikertelen /refund
        // után a szándék érintetlen marad a tudatos újrapróbáláshoz.
        $details->clearRefund();
    }

    public function supports($request): bool
    {
        return $request instanceof Refund && $request->getModel() instanceof \ArrayAccess;
    }
}
