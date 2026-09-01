<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\StatusMap;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;

/**
 * Tiszta olvasás: a tárolt állapotot fordítja Payum státusszá.
 *
 * SZÁNDÉKOSAN NEM `ApiAwareInterface`. Ez az osztály nem tud hálózatozni,
 * és nem is szabad tudnia: a lekérdezés a `SyncAction` dolga. A régi
 * implementáció itt indított `/query` hívást minden státuszolvasásnál, ami
 * egy admin listaoldalon fizetésenként egy HTTP kérést jelentett volna.
 */
final class StatusAction implements ActionInterface
{
    /**
     * Az `instanceof` őrök nem az `assertSupports()`-ot ismétlik meg —
     * azok adják a statikus elemzőnek a típusszűkítést, amit egy típus-
     * annotáció csak állítana, nem bizonyítana.
     */
    public function execute($request): void
    {
        if (!$request instanceof GetStatusInterface) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $model = $request->getModel();

        if (!$model instanceof \ArrayAccess) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        StatusMap::apply($request, Details::fromModel($model)->state()->status);
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface &&
            $request->getModel() instanceof \ArrayAccess;
    }
}
