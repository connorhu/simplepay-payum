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
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHttpRequest;

class CaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
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
        /** @var Capture $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (isset($model['transactionId'])) {
            $this->handleReturn($model);
            return;
        }

        $this->initiatePayment($model);
    }

    private function initiatePayment(ArrayObject $model): void
    {
        $data = array_merge((array) $model, [
            'salt'       => bin2hex(random_bytes(16)),
            'merchant'   => $this->api->getMerchantId(),
            'sdkVersion' => 'CodeConjure_SimplePayPayum/1.0.0',
        ]);

        $result = $this->api->startPayment($data);

        $model['transactionId'] = $result['transactionId'];
        $model['paymentUrl']    = $result['paymentUrl'];

        throw new HttpRedirect($result['paymentUrl']);
    }

    private function handleReturn(ArrayObject $model): void
    {
        $httpRequest = new GetHttpRequest();
        $this->gateway->execute($httpRequest);

        $r = $httpRequest->query['r'] ?? null;
        $s = $httpRequest->query['s'] ?? null;

        if (!$r || !$s || !$this->api->verifySignature($r, $s)) {
            $model['status'] = 'FRAUD';
            return;
        }

        $returnData    = json_decode(base64_decode($r), true) ?? [];
        $transactionId = $returnData['t'] ?? $model['transactionId'];

        $result          = $this->api->queryPayment([
            'salt'          => bin2hex(random_bytes(16)),
            'merchant'      => $this->api->getMerchantId(),
            'transactionId' => $transactionId,
            'orderRef'      => $model['orderRef'],
        ]);
        $model['status'] = $result['status'] ?? 'UNKNOWN';
    }

    public function supports($request): bool
    {
        return $request instanceof Capture
            && $request->getModel() instanceof \ArrayAccess;
    }
}
