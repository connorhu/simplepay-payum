<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Action;

use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHttpRequest;
use PHPUnit\Framework\TestCase;

class CaptureActionTest extends TestCase
{
    private function makeApi(array $startResponse = [], array $queryResponse = []): Api
    {
        $factory    = new Psr17Factory();
        $mockClient = new Client();
        if ($startResponse) {
            $mockClient->addResponse(new Response(200, [], json_encode($startResponse)));
        }
        if ($queryResponse) {
            $mockClient->addResponse(new Response(200, [], json_encode($queryResponse)));
        }
        return new Api('MERCHANT', 'SECRET', true, $mockClient, $factory, $factory);
    }

    private function makeGateway(array $queryParams = []): GatewayInterface
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(function ($request) use ($queryParams): void {
            if ($request instanceof GetHttpRequest) {
                $request->query = $queryParams;
            }
        });
        return $gateway;
    }

    public function testRedirectsToPaymentUrlOnFirstCall(): void
    {
        $api = $this->makeApi(startResponse: [
            'transactionId' => 'TXN-1',
            'paymentUrl'    => 'https://sandbox.simplepay.hu/pay/TXN-1',
        ]);

        $action = new CaptureAction();
        $action->setApi($api);
        $action->setGateway($this->makeGateway());

        $model = new ArrayObject(['orderRef' => 'ORDER-1', 'total' => 5000, 'currency' => 'HUF']);

        try {
            $action->execute(new Capture($model));
            self::fail('Expected HttpRedirect reply');
        } catch (HttpRedirect $reply) {
            self::assertStringContainsString('TXN-1', $reply->getUrl());
        }
    }

    public function testSavesTransactionIdToModel(): void
    {
        $api = $this->makeApi(startResponse: [
            'transactionId' => 'TXN-42',
            'paymentUrl'    => 'https://sandbox.simplepay.hu/pay/TXN-42',
        ]);

        $action = new CaptureAction();
        $action->setApi($api);
        $action->setGateway($this->makeGateway());

        $model = new ArrayObject(['orderRef' => 'ORDER-1', 'total' => 5000]);
        try {
            $action->execute(new Capture($model));
        } catch (HttpRedirect) {}

        self::assertSame('TXN-42', $model['transactionId']);
    }

    public function testHandlesReturnWithValidSignature(): void
    {
        $secretKey = 'SECRET';
        $rParam    = base64_encode(json_encode(['t' => 'TXN-1', 'e' => 'SUCCESS']));
        $sParam    = base64_encode(hash_hmac('sha256', $rParam, $secretKey, true));

        $api = $this->makeApi(queryResponse: ['status' => 'COMPLETE']);
        $action = new CaptureAction();
        $action->setApi($api);
        $action->setGateway($this->makeGateway(['r' => $rParam, 's' => $sParam]));

        $model = new ArrayObject(['orderRef' => 'ORDER-1', 'transactionId' => 'TXN-1']);
        $action->execute(new Capture($model));

        self::assertSame('COMPLETE', $model['status']);
    }

    public function testSetsStatusToFraudOnInvalidSignature(): void
    {
        $api = $this->makeApi();
        $action = new CaptureAction();
        $action->setApi($api);
        $action->setGateway($this->makeGateway(['r' => 'abc', 's' => 'invalidsig']));

        $model = new ArrayObject(['orderRef' => 'ORDER-1', 'transactionId' => 'TXN-1']);
        $action->execute(new Capture($model));

        self::assertSame('FRAUD', $model['status']);
    }
}
