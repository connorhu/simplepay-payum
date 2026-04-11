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
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;

class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
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
        /** @var Notify $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $httpRequest = new GetHttpRequest();
        $this->gateway->execute($httpRequest);

        $body      = $httpRequest->content ?? '';
        $signature = $httpRequest->headers['signature'][0] ?? '';

        if (!$this->api->verifySignature($body, $signature)) {
            throw new HttpResponse('', 400);
        }

        $data  = json_decode($body, true) ?? [];
        $model = ArrayObject::ensureArrayObject($request->getModel());

        $result          = $this->api->queryPayment([
            'salt'          => bin2hex(random_bytes(16)),
            'merchant'      => $this->api->getMerchantId(),
            'transactionId' => $data['transactionId'] ?? $model['transactionId'],
            'orderRef'      => $data['orderRef'] ?? $model['orderRef'],
        ]);
        $model['status'] = $result['status'] ?? $data['status'] ?? 'UNKNOWN';

        throw new HttpResponse('OK', 200);
    }

    public function supports($request): bool
    {
        return $request instanceof Notify
            && $request->getModel() instanceof \ArrayAccess;
    }
}
