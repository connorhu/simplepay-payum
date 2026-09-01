<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\Client;
use CodeConjure\SimplePay\Config;
use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    public function testItCarriesTheClientAndTheMerchantLevelFacts(): void
    {
        $factory = new Psr17Factory();
        $client = new Client(
            new Config('PUBLICTESTHUF', 'titok', Environment::Sandbox),
            new MockHttpClient(),
            $factory,
            $factory,
        );

        $api = new Api($client, 'PUBLICTESTHUF', Environment::Sandbox, Currency::HUF);

        self::assertSame($client, $api->client);
        self::assertSame('PUBLICTESTHUF', $api->merchant);
        self::assertSame(Environment::Sandbox, $api->environment);
        self::assertSame(Currency::HUF, $api->currency);
    }
}
