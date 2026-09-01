<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePay\Client;
use CodeConjure\SimplePay\Config;
use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Action\ResolveIpnAction;
use CodeConjure\SimplePayPayum\Action\StatusAction;
use CodeConjure\SimplePayPayum\Action\SyncAction;
use CodeConjure\SimplePayPayum\Exception\InvalidGatewayConfigException;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use Payum\Core\GatewayFactoryInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A `simplepay` Payum gateway összeszerelése.
 *
 * Négy konfigurációs kulcsot olvas: `merchant`, `secretKey`, `environment`,
 * `currency`. A `currency` azért kell, mert a SimplePay merchant azonosító
 * pénznemhez kötött.
 *
 * A csomag NEM hoz PSR-18 klienst — azt az alkalmazás adja be. A protokoll-
 * csomag konstruktora validálja a merchantot és a titkot; nem építünk fölé
 * saját, elnézőbb ellenőrzést.
 */
final class SimplePayGatewayFactory extends GatewayFactory
{
    /** @param array<string, mixed> $defaultConfig */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
        array $defaultConfig = [],
        ?GatewayFactoryInterface $coreGatewayFactory = null,
    ) {
        parent::__construct($defaultConfig, $coreGatewayFactory);
    }

    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => 'simplepay',
            'payum.factory_title' => 'SimplePay',
            'payum.action.capture' => new CaptureAction(),
            'payum.action.notify' => new NotifyAction(new TransitionGuard(), $this->logger),
            'payum.action.status' => new StatusAction(),
            'payum.action.sync' => new SyncAction(new TransitionGuard(), $this->logger),
            'payum.action.refund' => new RefundAction(),
            'payum.action.resolve_ipn' => new ResolveIpnAction(),
            'payum.api' => function (ArrayObject $config): Api {
                $merchant = $this->string($config, 'merchant');
                $environment = $this->enum(Environment::class, $this->string($config, 'environment'), 'environment');
                $currency = $this->enum(Currency::class, $this->string($config, 'currency'), 'currency');

                return new Api(
                    new Client(
                        new Config($merchant, $this->string($config, 'secretKey'), $environment),
                        $this->httpClient,
                        $this->requestFactory,
                        $this->streamFactory,
                    ),
                    $merchant,
                    $environment,
                    $currency,
                );
            },
        ]);

        parent::populateConfig($config);
    }

    private function string(ArrayObject $config, string $key): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private function enum(string $enum, string $value, string $key): \BackedEnum
    {
        return $enum::tryFrom($value) ?? throw new InvalidGatewayConfigException(sprintf(
            'A SimplePay gateway "%s" beállításának "%s" értéke ismeretlen. Az ismertek: %s.',
            $key,
            $value,
            implode(', ', array_column($enum::cases(), 'value')),
        ));
    }
}
