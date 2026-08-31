<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\Client;
use CodeConjure\SimplePay\Config;
use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SimplePay\Signature;
use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

/**
 * Valódi protokoll-klienst épít egy mock PSR-18 kliens fölé.
 *
 * A `CodeConjure\SimplePay\Client` `final` és nincs interfésze — ez az 1.
 * fázis tudatos döntése —, tehát nem mockolható. Cserébe minden teszt a
 * valódi JSON-szerializáláson, a valódi HMAC-SHA384 aláíráson és a valódi
 * válasz-parse-oláson keresztül fut: egy elrontott mezőnév itt kiderül.
 */
final class ApiFactory
{
    public const string MERCHANT = 'PUBLICTESTHUF';

    public const string SECRET = 'FxDa5w314kLlNseq2sKuVwaqZshZT5d6';

    public static function create(MockHttpClient $httpClient): Api
    {
        $factory = new Psr17Factory();

        return new Api(
            new Client(
                new Config(self::MERCHANT, self::SECRET, Environment::Sandbox),
                $httpClient,
                $factory,
                $factory,
            ),
            self::MERCHANT,
            Environment::Sandbox,
            Currency::HUF,
        );
    }

    /**
     * A SimplePay minden választ aláír; a protokoll-kliens ellenőrzi is.
     * A mock válaszainak tehát valódi aláírást kell hordozniuk.
     */
    public static function signedResponse(string $body, int $status = 200): Response
    {
        return new Response(
            $status,
            ['Signature' => new Signature(self::SECRET)->sign($body)],
            $body,
        );
    }
}
