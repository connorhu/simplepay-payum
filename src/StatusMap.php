<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePay\TransactionStatus;
use Payum\Core\Request\GetStatusInterface;

/**
 * SimplePay tranzakció-státusz → Payum státusz.
 *
 * A `match` KIMERÍTŐ, `default` ág nélkül: ha a protokoll-csomag új
 * `TransactionStatus` esetet vesz fel, ez fordítási hibát ad.
 *
 * A régi implementáció ehelyett egy 29 elemű, kisbetűs string-térképet
 * használt (`paid`, `captured`, `completed`, …), amiben a SimplePay 11
 * valódi státuszából csak három szerepelt. A `FINISHED` — a sikeres fizetés —
 * nem volt köztük, tehát `unknown`-ra képződött; a webhook-feldolgozó pedig
 * épp az `unknown` státuszt utasította el. Így minden sikeres fizetés
 * elutasításra került volna.
 */
final class StatusMap
{
    public static function apply(GetStatusInterface $request, ?TransactionStatus $status): void
    {
        if (null === $status) {
            $request->markNew();

            return;
        }

        match ($status) {
            TransactionStatus::Init,
            TransactionStatus::InPayment,
            TransactionStatus::InFraud => $request->markPending(),
            // A kétlépéses fizetést ez a csomag nem támogatja, tehát ez az
            // állapot nem várható. A leképezés mégis őszinte marad: az
            // AUTHORIZED lefoglalt, de le nem hívott összeg, nem "függőben".
            TransactionStatus::Authorized => $request->markAuthorized(),
            TransactionStatus::Finished => $request->markCaptured(),
            TransactionStatus::Cancelled,
            TransactionStatus::Timeout => $request->markCanceled(),
            TransactionStatus::NotAuthorized,
            TransactionStatus::Fraud => $request->markFailed(),
            TransactionStatus::Reversed,
            TransactionStatus::Refund => $request->markRefunded(),
        };
    }
}
