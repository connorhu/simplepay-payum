<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePay\Client;
use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;

/**
 * A Payum `payum.api` értéke: a protokoll-kliens és a hozzá tartozó,
 * merchant-szintű tények.
 *
 * A `$currency` azért van itt, mert a SimplePay merchant azonosító
 * PÉNZNEMHEZ KÖTÖTT: egy merchant egy pénznemet fogad, több pénznemhez
 * több merchant kell. Ez nem a keretrendszer tulajdonsága, hanem a
 * szolgáltatásé — tehát a Payum-rétegben a helye, nem a fölötte lévőben.
 *
 * A `$merchant` redundánsnak tűnhet (a `Client` konfigurációjában is benne
 * van), de a `Config` nem publikálja kifelé, és az actionöknek szükségük
 * van rá naplózáshoz és a state feltöltéséhez.
 */
final readonly class Api
{
    public function __construct(
        public Client $client,
        public string $merchant,
        public Environment $environment,
        public Currency $currency,
    ) {
    }
}
