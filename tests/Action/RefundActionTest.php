<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Action;

use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Request\Refund;
use PHPUnit\Framework\TestCase;

class RefundActionTest extends TestCase
{
    private function makeApi(int $statusCode, array $responseBody): Api
    {
        $factory    = new Psr17Factory();
        $mockClient = new Client();
        $mockClient->addResponse(new Response($statusCode, [], json_encode($responseBody)));
        return new Api('MERCHANT', 'SECRET', true, $mockClient, $factory, $factory);
    }

    public function testRefundsPaymentAndSetsStatus(): void
    {
        $action = new RefundAction();
        $action->setApi($this->makeApi(200, ['status' => 'REFUND', 'refundedAmount' => 5000]));
        $action->setGateway($this->createMock(GatewayInterface::class));

        $model = new ArrayObject([
            'transactionId' => 'TXN-1',
            'orderRef'      => 'ORDER-1',
            'total'         => 5000,
            'currency'      => 'HUF',
        ]);
        $action->execute(new Refund($model));

        self::assertSame('REFUND', $model['status']);
    }

    public function testThrowsWhenRequiredFieldsMissing(): void
    {
        $action = new RefundAction();
        $action->setApi($this->makeApi(200, [])); // won't be called, but setup needed
        $action->setGateway($this->createMock(GatewayInterface::class));

        $this->expectException(\LogicException::class);
        $action->execute(new Refund(new ArrayObject(['transactionId' => 'TXN-1', 'total' => 5000])));
        // missing 'orderRef'
    }

    public function testThrowsOnApiError(): void
    {
        $action = new RefundAction();
        $action->setApi($this->makeApi(500, ['error' => 'server error']));
        $action->setGateway($this->createMock(GatewayInterface::class));

        $this->expectException(\LogicException::class);
        $action->execute(new Refund(new ArrayObject([
            'orderRef'      => 'ORD-1',
            'transactionId' => 'TXN-1',
            'total'         => 5000,
            'currency'      => 'HUF',
        ])));
    }
}
