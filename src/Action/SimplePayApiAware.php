<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePayPayum\Api;
use Payum\Core\ApiAwareTrait;

/**
 * A Payum `ApiAwareTrait` a `$this->api` mezőt `mixed`-ként tartja, mert
 * tetszőleges gateway tetszőleges API-objektumot kaphat. Ez a trait adja
 * hozzá a csomag saját, tipizált hozzáférését — ellenőrzéssel, nem
 * annotációval: ha a Payum valaha `setApi()` nélkül hívna `execute()`-ot,
 * abból érthető hiba legyen, ne fatal egy `null`-on.
 */
trait SimplePayApiAware
{
    use ApiAwareTrait;

    private function api(): Api
    {
        $api = $this->api;

        if (!$api instanceof Api) {
            throw new \LogicException(sprintf(
                'A(z) %s actionhöz nem lett SimplePay Api beállítva.',
                static::class,
            ));
        }

        return $api;
    }
}
