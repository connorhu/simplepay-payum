<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * A Payum `details` tömbből hiányzik egy kötelező adat.
 *
 * Ez mindig programozói hiba: azt jelenti, hogy a felső réteg (a `Convert`
 * action vagy a hívó parancs) nem futott le, vagy nem azt írta be, amit
 * ez a csomag vár. Nincs olyan futásidejű állapot, amiben egy `Capture`
 * jogosan találna üres `simplepay_request` kulcsot.
 */
final class MissingDetailsException extends \LogicException implements SimplePayPayumException
{
}
