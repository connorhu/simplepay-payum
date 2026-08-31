<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Bundle\Builder;

use CodeConjure\SimplePayPayum\SimplePayGatewayFactory;
use Payum\Core\Bridge\Symfony\Builder\GatewayFactoryBuilder;
use Payum\Core\GatewayFactoryInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ez az EGYETLEN bekötési pont, amit ez a csomag regisztrál.
 *
 * A `sylius.gateway_configuration_type` tag SZÁNDÉKOSAN nincs itt — az a
 * Sylius plugin dolga. Ez a két tag választja szét a két csomagot a
 * Symfony konténerben.
 */
#[AutoconfigureTag(name: 'payum.gateway_factory_builder', attributes: ['factory' => 'simplepay'])]
final class SimplePayGatewayFactoryBuilder extends GatewayFactoryBuilder
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(SimplePayGatewayFactory::class);
    }

    /** @param array<string, mixed> $defaultConfig */
    public function build(array $defaultConfig, GatewayFactoryInterface $coreGatewayFactory): GatewayFactoryInterface
    {
        return new SimplePayGatewayFactory(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
            $defaultConfig,
            $coreGatewayFactory,
        );
    }
}
