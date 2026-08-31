<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SimplePay\Exception\ConfigurationException;
use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Action\ResolveIpnAction;
use CodeConjure\SimplePayPayum\Action\StatusAction;
use CodeConjure\SimplePayPayum\Action\SyncAction;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Exception\InvalidGatewayConfigException;
use CodeConjure\SimplePayPayum\SimplePayGatewayFactory;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;

final class SimplePayGatewayFactoryTest extends TestCase
{
    private function factory(): SimplePayGatewayFactory
    {
        $psr17 = new Psr17Factory();

        return new SimplePayGatewayFactory(new MockHttpClient(), $psr17, $psr17);
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        return [
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'FxDa5w314kLlNseq2sKuVwaqZshZT5d6',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ];
    }

    public function testItNamesItselfSimplepay(): void
    {
        $config = $this->factory()->createConfig(self::config());

        self::assertSame('simplepay', $config['payum.factory_name']);
        self::assertSame('SimplePay', $config['payum.factory_title']);
    }

    public function testItRegistersEverySimplePayAction(): void
    {
        $config = $this->factory()->createConfig(self::config());

        self::assertInstanceOf(CaptureAction::class, $config['payum.action.capture']);
        self::assertInstanceOf(NotifyAction::class, $config['payum.action.notify']);
        self::assertInstanceOf(StatusAction::class, $config['payum.action.status']);
        self::assertInstanceOf(SyncAction::class, $config['payum.action.sync']);
        self::assertInstanceOf(RefundAction::class, $config['payum.action.refund']);
        self::assertInstanceOf(ResolveIpnAction::class, $config['payum.action.resolve_ipn']);
    }

    public function testItBuildsAnApiFromTheGatewayConfiguration(): void
    {
        $config = $this->factory()->createConfig(self::config());
        $api = $config['payum.api'](ArrayObject::ensureArrayObject($config));

        self::assertInstanceOf(Api::class, $api);
        self::assertSame('PUBLICTESTHUF', $api->merchant);
        self::assertSame(Environment::Sandbox, $api->environment);
        self::assertSame(Currency::HUF, $api->currency);
    }

    public function testAnUnknownEnvironmentIsRejectedByName(): void
    {
        $config = self::config();
        $config['environment'] = 'staging';

        $built = $this->factory()->createConfig($config);

        $this->expectException(InvalidGatewayConfigException::class);
        $this->expectExceptionMessage('staging');

        $built['payum.api'](ArrayObject::ensureArrayObject($built));
    }

    public function testAnUnknownCurrencyIsRejectedByName(): void
    {
        $config = self::config();
        $config['currency'] = 'GBP';

        $built = $this->factory()->createConfig($config);

        $this->expectException(InvalidGatewayConfigException::class);
        $this->expectExceptionMessage('GBP');

        $built['payum.api'](ArrayObject::ensureArrayObject($built));
    }

    public function testAMissingMerchantIsRejectedByTheProtocolPackageNotSilentlyAccepted(): void
    {
        $config = self::config();
        $config['merchant'] = '';

        $built = $this->factory()->createConfig($config);

        $this->expectException(ConfigurationException::class);

        $built['payum.api'](ArrayObject::ensureArrayObject($built));
    }

    public function testAMissingSecretKeyIsRejected(): void
    {
        $config = self::config();
        $config['secretKey'] = '';

        $built = $this->factory()->createConfig($config);

        $this->expectException(ConfigurationException::class);

        $built['payum.api'](ArrayObject::ensureArrayObject($built));
    }
}
