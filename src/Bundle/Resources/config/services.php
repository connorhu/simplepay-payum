<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use CodeConjure\SimplePayPayum\Bundle\Builder\SimplePayGatewayFactoryBuilder;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('codeconjure_simplepay_payum.gateway_factory_builder', SimplePayGatewayFactoryBuilder::class)
        ->args([
            service(ClientInterface::class),
            service(RequestFactoryInterface::class),
            service(StreamFactoryInterface::class),
            service('logger')->nullOnInvalid(),
        ])
        ->tag('payum.gateway_factory_builder', ['factory' => 'simplepay']);
};
