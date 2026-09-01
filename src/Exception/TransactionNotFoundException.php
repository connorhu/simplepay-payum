<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * A `/query` lekérdezés nem adott vissza tranzakciót arra az azonosítóra,
 * amit a `simplepay_state` tárol.
 *
 * Nem néma `null`: ha egy elindított tranzakciót a SimplePay nem ismer,
 * az valódi ellentmondás a két rendszer állapota között, és látszania kell.
 */
final class TransactionNotFoundException extends \RuntimeException implements SimplePayPayumException
{
}
