<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Action;

use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;

class NotifyActionTest extends TestCase
{
    private function makeApi(array $queryResponse = []): Api
    {
        $factory    = new Psr17Factory();
        $mockClient = new Client();
        if ($queryResponse) {
            $mockClient->addResponse(new Response(200, [], json_encode($queryResponse)));
        }
        return new Api('MERCHANT', 'SECRET', true, $mockClient, $factory, $factory);
    }

    private function makeGateway(string $body, string $signature): GatewayInterface
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(function ($request) use ($body, $signature): void {
            if ($request instanceof GetHttpRequest) {
                $request->content = $body;
                $request->headers = ['signature' => [$signature]];
            }
        });
        return $gateway;
    }

    public function testReturns200AndUpdatesStatusForValidSignature(): void
    {
        $body      = json_encode(['transactionId' => 'TXN-1', 'orderRef' => 'ORDER-1']);
        $secretKey = 'SECRET';
        $signature = base64_encode(hash_hmac('sha256', $body, $secretKey, true));

        $action = new NotifyAction();
        $action->setApi($this->makeApi(queryResponse: ['status' => 'COMPLETE']));
        $action->setGateway($this->makeGateway($body, $signature));

        $model  = new ArrayObject(['orderRef' => 'ORDER-1', 'transactionId' => 'TXN-1']);
        $notify = new Notify($model);

        try {
            $action->execute($notify);
            self::fail('Expected HttpResponse reply');
        } catch (HttpResponse $reply) {
            self::assertSame(200, $reply->getStatusCode());
        }

        self::assertSame('COMPLETE', $model['status']);
    }

    public function testReturns400ForInvalidSignature(): void
    {
        $body   = json_encode(['transactionId' => 'TXN-1']);
        $action = new NotifyAction();
        $action->setApi($this->makeApi());
        $action->setGateway($this->makeGateway($body, 'invalidsig'));

        try {
            $action->execute(new Notify(new ArrayObject([])));
            self::fail('Expected HttpResponse reply');
        } catch (HttpResponse $reply) {
            self::assertSame(400, $reply->getStatusCode());
        }
    }
}
