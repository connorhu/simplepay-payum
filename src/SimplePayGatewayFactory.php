<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Action\ConvertPaymentAction;
use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Action\StatusAction;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;

class SimplePayGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'sandbox'             => false,
            'payum.factory_name'  => 'simplepay',
            'payum.factory_title' => 'OTP SimplePay',

            'payum.action.capture'         => new CaptureAction(),
            'payum.action.notify'          => new NotifyAction(),
            'payum.action.refund'          => new RefundAction(),
            'payum.action.status'          => new StatusAction(),
            'payum.action.convert_payment' => new ConvertPaymentAction(),
        ]);

        if (!$config->offsetExists('payum.api')) {
            $config->validateNotEmpty(['merchant_id', 'secret_key']);

            $config['payum.api'] = new Api(
                merchantId:     $config['merchant_id'],
                secretKey:      $config['secret_key'],
                sandbox:        (bool) $config['sandbox'],
                httpClient:     $config['http_client'] ?? Psr18ClientDiscovery::find(),
                requestFactory: $config['request_factory'] ?? Psr17FactoryDiscovery::findRequestFactory(),
                streamFactory:  $config['stream_factory'] ?? Psr17FactoryDiscovery::findStreamFactory(),
            );
        }
    }
}
