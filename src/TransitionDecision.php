<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

/**
 * Mit kezdjünk egy beérkezett státusszal.
 *
 * A `Duplicate` és a `Rejected` egyaránt azt jelenti, hogy az állapotot nem
 * írjuk felül — de nem ugyanaz a helyzet, és nem ugyanaz a naplószint.
 * Mindkettőre megy aláírt IPN-visszaigazolás.
 */
enum TransitionDecision
{
    case Apply;
    case Duplicate;
    case Rejected;
}
