<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Request\ResolveSimplePayIpn;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Exception\RequestNotSupportedException;

/**
 * Ellenőrzi az IPN aláírását és a merchant egyezését, majd kiadja a
 * parse-olt üzenetet — állapotot nem ír.
 *
 * Az aláírás így kétszer számolódik (itt és a `NotifyAction`-ben).
 * HMAC-SHA384 egy néhány száz bájtos törzsön: elhanyagolható ár azért, hogy
 * minden kriptográfia a Payum és a protokoll rétegben maradjon, és a hívó
 * soha ne olvasson aláíratlan adatot.
 */
final class ResolveIpnAction implements ActionInterface, ApiAwareInterface
{
    use SimplePayApiAware;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    /**
     * Az `instanceof` őr nem az `assertSupports()`-ot ismétli meg —
     * az adja a statikus elemzőnek a típusszűkítést, amit egy típus-
     * annotáció csak állítana, nem bizonyítana.
     */
    public function execute($request): void
    {
        if (!$request instanceof ResolveSimplePayIpn) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $request->setMessage($this->api()->client->ipn($request->rawBody, $request->signature)->message());
    }

    public function supports($request): bool
    {
        return $request instanceof ResolveSimplePayIpn;
    }
}
