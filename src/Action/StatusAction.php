<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;

class StatusAction implements ActionInterface
{
    public function execute($request): void
    {
        /** @var GetStatusInterface $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (!isset($model['status']) || $model['status'] === '') {
            $request->markNew();
            return;
        }

        match ($model['status']) {
            'COMPLETE'                            => $request->markCaptured(),
            'WAITING', 'IN_PAYMENT'               => $request->markPending(),
            'CANCELLED'                           => $request->markCanceled(),
            'TIMEOUT'                             => $request->markExpired(),
            'REFUND', 'REFUND_PARTIAL'            => $request->markRefunded(),
            'FRAUD', 'CARD_DECLINED', 'ERROR'     => $request->markFailed(),
            default                               => $request->markUnknown(),
        };
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface
            && $request->getModel() instanceof \ArrayAccess;
    }
}
