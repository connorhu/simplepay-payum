<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests;

use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Action\ConvertPaymentAction;
use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Action\StatusAction;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\SimplePayGatewayFactory;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;

class SimplePayGatewayFactoryTest extends TestCase
{
    private function invokePopulateConfig(array $userConfig): ArrayObject
    {
        $factory = new SimplePayGatewayFactory();
        $config  = new ArrayObject($userConfig);

        $method = new \ReflectionMethod(SimplePayGatewayFactory::class, 'populateConfig');
        $method->invoke($factory, $config);

        return $config;
    }

    public function testFactoryName(): void
    {
        $config = $this->invokePopulateConfig([
            'merchant_id' => 'M', 'secret_key' => 'S',
            'http_client' => new Client(), 'request_factory' => new Psr17Factory(), 'stream_factory' => new Psr17Factory(),
        ]);
        self::assertSame('simplepay', $config['payum.factory_name']);
        self::assertSame('OTP SimplePay', $config['payum.factory_title']);
    }

    public function testRegistersAllActions(): void
    {
        $config = $this->invokePopulateConfig([
            'merchant_id' => 'M', 'secret_key' => 'S',
            'http_client' => new Client(), 'request_factory' => new Psr17Factory(), 'stream_factory' => new Psr17Factory(),
        ]);

        self::assertInstanceOf(CaptureAction::class, $config['payum.action.capture']);
        self::assertInstanceOf(NotifyAction::class, $config['payum.action.notify']);
        self::assertInstanceOf(RefundAction::class, $config['payum.action.refund']);
        self::assertInstanceOf(StatusAction::class, $config['payum.action.status']);
        self::assertInstanceOf(ConvertPaymentAction::class, $config['payum.action.convert_payment']);
    }

    public function testCreatesApiInstance(): void
    {
        $config = $this->invokePopulateConfig([
            'merchant_id' => 'MERCHANT', 'secret_key' => 'SECRET', 'sandbox' => true,
            'http_client' => new Client(), 'request_factory' => new Psr17Factory(), 'stream_factory' => new Psr17Factory(),
        ]);

        self::assertInstanceOf(Api::class, $config['payum.api']);
        self::assertSame('MERCHANT', $config['payum.api']->getMerchantId());
    }

    public function testSandboxDefaultsFalse(): void
    {
        $config = $this->invokePopulateConfig([
            'merchant_id' => 'M', 'secret_key' => 'S',
            'http_client' => new Client(), 'request_factory' => new Psr17Factory(), 'stream_factory' => new Psr17Factory(),
        ]);
        self::assertFalse($config['sandbox']);
    }
}
