<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Model;

/**
 * Mi történt egy beérkezett IPN-nel.
 *
 * A `Duplicate` és a `Rejected` egyaránt azt jelenti, hogy az állapotot NEM
 * írtuk felül — de nem ugyanaz a helyzet: a duplikátum a SimplePay ismétlése
 * (várható), az elutasítás az állapotgépeink nézeteltérése (nem várható).
 * Mindkettőre megy aláírt visszaigazolás; csak a naplószint különbözik.
 */
enum IpnOutcome: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
}
