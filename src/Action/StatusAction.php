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
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        // Az assertSupports() a supports()-on át már ellenőrizte a típust,
        // de az `execute($request)` paramétere Payum felől tipizálatlan
        // marad — a statikus elemzőnek ez az önmagára visszaíró sor mondja
        // meg, mit tudunk már biztosan a $request-ről és a $model-ről.
        /** @var GetStatusInterface $request */
        $request = $request;

        $model = $request->getModel();

        /** @var \ArrayAccess<string, mixed> $model */
        $model = $model;

        StatusMap::apply($request, Details::fromModel($model)->state()->status);
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface &&
            $request->getModel() instanceof \ArrayAccess;
    }
}
