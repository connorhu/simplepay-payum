<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * Minden, a csomag által dobott kivétel közös interfésze — egyetlen `catch`
 * mindet elkapja.
 *
 * A hierarchia nem aszerint tagolódik, hol keletkezett a hiba, hanem aszerint,
 * hogy a hívónak mit kell tennie vele: a `LogicException` leszármazottak
 * programozói hibát jeleznek (kódot vagy konfigurációt kell javítani), a
 * `RuntimeException` leszármazottak futásidejű, adatból eredő helyzetet.
 */
interface SimplePayPayumException extends \Throwable
{
}
