<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\TransitionDecision;
use CodeConjure\SimplePayPayum\TransitionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransitionGuardTest extends TestCase
{
    /** @return iterable<string, array{?TransactionStatus, TransactionStatus, TransitionDecision}> */
    public static function transitions(): iterable
    {
        yield 'első értesítés bármit elfogad' => [
            null, TransactionStatus::Finished, TransitionDecision::Apply,
        ];
        yield 'INIT-ből FINISHED' => [
            TransactionStatus::Init, TransactionStatus::Finished, TransitionDecision::Apply,
        ];
        yield 'INPAYMENT-ből NOTAUTHORIZED' => [
            TransactionStatus::InPayment, TransactionStatus::NotAuthorized, TransitionDecision::Apply,
        ];
        yield 'AUTHORIZED-ból FINISHED' => [
            TransactionStatus::Authorized, TransactionStatus::Finished, TransitionDecision::Apply,
        ];
        yield 'INFRAUD-ból FRAUD' => [
            TransactionStatus::InFraud, TransactionStatus::Fraud, TransitionDecision::Apply,
        ];
        yield 'ugyanaz a státusz duplikátum' => [
            TransactionStatus::Finished, TransactionStatus::Finished, TransitionDecision::Duplicate,
        ];
        yield 'INIT ismétlődése is duplikátum' => [
            TransactionStatus::Init, TransactionStatus::Init, TransitionDecision::Duplicate,
        ];
        yield 'FINISHED-ből REFUND elfogadható' => [
            TransactionStatus::Finished, TransactionStatus::Refund, TransitionDecision::Apply,
        ];
        yield 'FINISHED-ből REVERSED elfogadható' => [
            TransactionStatus::Finished, TransactionStatus::Reversed, TransitionDecision::Apply,
        ];
        yield 'FINISHED-ből CANCELLED nem' => [
            TransactionStatus::Finished, TransactionStatus::Cancelled, TransitionDecision::Rejected,
        ];
        yield 'FINISHED-ből INIT nem' => [
            TransactionStatus::Finished, TransactionStatus::Init, TransitionDecision::Rejected,
        ];
        yield 'CANCELLED után semmi' => [
            TransactionStatus::Cancelled, TransactionStatus::Finished, TransitionDecision::Rejected,
        ];
        yield 'TIMEOUT után semmi' => [
            TransactionStatus::Timeout, TransactionStatus::Finished, TransitionDecision::Rejected,
        ];
        yield 'NOTAUTHORIZED után semmi' => [
            TransactionStatus::NotAuthorized, TransactionStatus::Finished, TransitionDecision::Rejected,
        ];
        yield 'REFUND után semmi' => [
            TransactionStatus::Refund, TransactionStatus::Finished, TransitionDecision::Rejected,
        ];
    }

    #[DataProvider('transitions')]
    public function testItDecidesAccordingToTheTable(
        ?TransactionStatus $current,
        TransactionStatus $incoming,
        TransitionDecision $expected,
    ): void {
        self::assertSame($expected, new TransitionGuard()->decide($current, $incoming));
    }

    public function testARefundNotificationAfterASuccessfulPaymentIsNotRejected(): void
    {
        // A régi implementáció táblája SUCCESSFUL => [] volt: egy sikeres
        // fizetés után SEMMIT nem fogadott el, tehát a jóváírás-értesítést
        // is elutasította volna. Ez a teszt őrzi a javítást.
        self::assertSame(
            TransitionDecision::Apply,
            new TransitionGuard()->decide(TransactionStatus::Finished, TransactionStatus::Refund),
        );
    }
}
