<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePay\TransactionStatus;

/**
 * Az átmenetek a `TransactionStatus` enumon vannak megfogalmazva, nem
 * kanonikus vödrökön — így nem lehet két különböző SimplePay állapotot
 * véletlenül azonosnak venni.
 *
 * | jelenlegi                                   | mi fogadható el       |
 * |---------------------------------------------|-----------------------|
 * | nincs még státusz                           | bármi                 |
 * | INIT, INPAYMENT, AUTHORIZED, INFRAUD        | bármi                 |
 * | FINISHED                                    | csak REFUND, REVERSED |
 * | egyéb végleges                              | semmi                 |
 *
 * A régi implementáció táblája `SUCCESSFUL => []`-t mondott: egy sikeres
 * fizetés után semmit nem fogadott el, tehát a jóváírás-értesítést sem.
 */
final class TransitionGuard
{
    public function decide(?TransactionStatus $current, TransactionStatus $incoming): TransitionDecision
    {
        if (null === $current) {
            return TransitionDecision::Apply;
        }

        if ($current === $incoming) {
            return TransitionDecision::Duplicate;
        }

        if (!$current->isFinal()) {
            return TransitionDecision::Apply;
        }

        if (TransactionStatus::Finished === $current && $this->isRefundOutcome($incoming)) {
            return TransitionDecision::Apply;
        }

        return TransitionDecision::Rejected;
    }

    private function isRefundOutcome(TransactionStatus $status): bool
    {
        return TransactionStatus::Refund === $status || TransactionStatus::Reversed === $status;
    }
}
