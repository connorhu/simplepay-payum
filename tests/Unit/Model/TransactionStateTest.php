<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Model;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Model\IpnOutcome;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use PHPUnit\Framework\TestCase;

final class TransactionStateTest extends TestCase
{
    private const string NOW = '2026-08-31T12:00:00+02:00';

    public function testAnEmptyStateRoundTripsToAnEmptyArray(): void
    {
        self::assertSame([], (new TransactionState())->toArray());
        self::assertNull(TransactionState::fromArray([])->transactionId);
        self::assertSame(0, TransactionState::fromArray([])->attempt);
        self::assertSame([], TransactionState::fromArray([])->ipnLog);
    }

    public function testItRoundTripsAFullyPopulatedState(): void
    {
        $array = [
            'transactionId' => '99844942',
            'status' => 'FINISHED',
            'paymentUrl' => 'https://sb-checkout.simplepay.hu/trx/abc',
            'timeout' => '2026-08-31T12:30:00+02:00',
            'attempt' => 2,
            'lastCheckedAt' => '2026-08-31T12:05:00+02:00',
            'method' => 'CARD',
            'paymentDate' => '2026-08-31T12:01:00+02:00',
            'finishDate' => '2026-08-31T12:02:00+02:00',
            'refundTransactionId' => '99844999',
            'refundTotal' => 500,
            'remainingTotal' => 500,
            'ipnLog' => [[
                'receivedAt' => '2026-08-31T12:03:00+02:00',
                'transactionId' => '99844942',
                'status' => 'FINISHED',
                'outcome' => 'applied',
                'repeatCount' => 1,
            ]],
            'lastErrorCodes' => [5321],
        ];

        self::assertSame($array, TransactionState::fromArray($array)->toArray());
    }

    public function testALiveTransactionIsOneThatHasAUrlANonFinalStatusAndAFutureTimeout(): void
    {
        $state = new TransactionState(
            transactionId: 'T1',
            status: TransactionStatus::Init,
            paymentUrl: 'https://sb-checkout.simplepay.hu/trx/abc',
            timeout: new \DateTimeImmutable('2026-08-31T12:30:00+02:00'),
        );

        self::assertTrue($state->isLive(new \DateTimeImmutable(self::NOW)));
    }

    public function testAnExpiredTransactionIsNotLive(): void
    {
        $state = new TransactionState(
            transactionId: 'T1',
            status: TransactionStatus::Init,
            paymentUrl: 'https://sb-checkout.simplepay.hu/trx/abc',
            timeout: new \DateTimeImmutable('2026-08-31T11:30:00+02:00'),
        );

        self::assertFalse($state->isLive(new \DateTimeImmutable(self::NOW)));
    }

    public function testAFinishedTransactionIsNotLiveEvenBeforeItsTimeout(): void
    {
        $state = new TransactionState(
            transactionId: 'T1',
            status: TransactionStatus::Finished,
            paymentUrl: 'https://sb-checkout.simplepay.hu/trx/abc',
            timeout: new \DateTimeImmutable('2026-08-31T12:30:00+02:00'),
        );

        self::assertFalse($state->isLive(new \DateTimeImmutable(self::NOW)));
    }

    public function testATransactionWithoutATimeoutIsNotLiveBecauseWeCannotKnow(): void
    {
        $state = new TransactionState(
            transactionId: 'T1',
            status: TransactionStatus::Init,
            paymentUrl: 'https://sb-checkout.simplepay.hu/trx/abc',
        );

        self::assertFalse($state->isLive(new \DateTimeImmutable(self::NOW)));
    }

    public function testTheFirstIpnEntryForAPairGetsRepeatCountOne(): void
    {
        $state = (new TransactionState())->withIpnEntry(
            new \DateTimeImmutable(self::NOW),
            '99844942',
            TransactionStatus::Finished,
            IpnOutcome::Applied,
        );

        self::assertCount(1, $state->ipnLog);
        self::assertSame(1, $state->ipnLog[0]->repeatCount);
    }

    public function testARepeatedPairIncrementsTheCounter(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        $state = (new TransactionState())
            ->withIpnEntry($now, '99844942', TransactionStatus::Finished, IpnOutcome::Applied)
            ->withIpnEntry($now, '99844942', TransactionStatus::Finished, IpnOutcome::Duplicate)
            ->withIpnEntry($now, '99844942', TransactionStatus::Finished, IpnOutcome::Duplicate);

        self::assertCount(3, $state->ipnLog);
        self::assertSame(3, $state->ipnLog[2]->repeatCount);
    }

    public function testADifferentStatusStartsItsOwnCounter(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        $state = (new TransactionState())
            ->withIpnEntry($now, '99844942', TransactionStatus::Init, IpnOutcome::Applied)
            ->withIpnEntry($now, '99844942', TransactionStatus::Finished, IpnOutcome::Applied);

        self::assertSame(1, $state->ipnLog[1]->repeatCount);
    }

    public function testTheLogKeepsOnlyTheMostRecentEntries(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $state = new TransactionState();

        for ($i = 0; $i < TransactionState::IPN_LOG_LIMIT + 5; ++$i) {
            $state = $state->withIpnEntry($now, 'T' . $i, TransactionStatus::Init, IpnOutcome::Applied);
        }

        self::assertCount(TransactionState::IPN_LOG_LIMIT, $state->ipnLog);
        self::assertSame('T24', $state->ipnLog[TransactionState::IPN_LOG_LIMIT - 1]->transactionId);
        self::assertSame('T5', $state->ipnLog[0]->transactionId);
    }

    public function testTrimmingTheLogDoesNotResetTheRepeatCounter(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $state = (new TransactionState())
            ->withIpnEntry($now, 'T', TransactionStatus::Finished, IpnOutcome::Applied);

        for ($i = 0; $i < TransactionState::IPN_LOG_LIMIT; ++$i) {
            $state = $state->withIpnEntry($now, 'T', TransactionStatus::Finished, IpnOutcome::Duplicate);
        }

        $last = $state->ipnLog[TransactionState::IPN_LOG_LIMIT - 1];

        self::assertSame(TransactionState::IPN_LOG_LIMIT + 1, $last->repeatCount);
    }
}
