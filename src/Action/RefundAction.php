<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePayPayum\Api;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Refund;

class RefundAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function setApi($api): void
    {
        if (!$api instanceof Api) {
            throw new UnsupportedApiException(sprintf('Expected %s instance.', Api::class));
        }
        $this->api = $api;
    }

    public function execute($request): void
    {
        /** @var Refund $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        try {
            $result = $this->api->refundPayment([
                'salt'          => bin2hex(random_bytes(16)),
                'merchant'      => $this->api->getMerchantId(),
                'orderRef'      => $model['orderRef'],
                'transactionId' => $model['transactionId'],
                'currency'      => $model['currency'] ?? 'HUF',
                'refundTotal'   => $model['total'],
            ]);
        } catch (\RuntimeException $e) {
            throw new \LogicException('Refund failed: ' . $e->getMessage(), previous: $e);
        }

        $model['status'] = 'REFUND';
    }

    public function supports($request): bool
    {
        return $request instanceof Refund
            && $request->getModel() instanceof \ArrayAccess;
    }
}
