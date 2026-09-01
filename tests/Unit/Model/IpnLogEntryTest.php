<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Model;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Model\IpnLogEntry;
use CodeConjure\SimplePayPayum\Model\IpnOutcome;
use PHPUnit\Framework\TestCase;

final class IpnLogEntryTest extends TestCase
{
    private static function entry(): IpnLogEntry
    {
        return new IpnLogEntry(
            receivedAt: new \DateTimeImmutable('2026-08-31T12:00:00+02:00'),
            transactionId: '99844942',
            status: TransactionStatus::Finished,
            outcome: IpnOutcome::Applied,
            repeatCount: 1,
        );
    }

    public function testItSerialisesToAnArrayWithAnAtomTimestamp(): void
    {
        self::assertSame([
            'receivedAt' => '2026-08-31T12:00:00+02:00',
            'transactionId' => '99844942',
            'status' => 'FINISHED',
            'outcome' => 'applied',
            'repeatCount' => 1,
        ], self::entry()->toArray());
    }

    public function testItRoundTripsThroughTheArrayForm(): void
    {
        $array = self::entry()->toArray();

        self::assertSame($array, IpnLogEntry::fromArray($array)->toArray());
    }

    public function testItMatchesOnTheTransactionIdAndStatusPairOnly(): void
    {
        $entry = self::entry();

        self::assertTrue($entry->matches('99844942', TransactionStatus::Finished));
        self::assertFalse($entry->matches('99844942', TransactionStatus::Cancelled));
        self::assertFalse($entry->matches('11111111', TransactionStatus::Finished));
    }

    public function testItRejectsAnUnknownOutcome(): void
    {
        $array = self::entry()->toArray();
        $array['outcome'] = 'talán';

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('talán');

        IpnLogEntry::fromArray($array);
    }

    /**
     * A `matches()` KIZÁRÓLAG a transactionId + status párra illeszkedhet. Ez a
     * teszt egy olyan bejegyzést használ, ami mind a három figyelmen kívül
     * hagyandó mezőben eltér az `entry()` fixture-től — ha bármelyik bekerülne
     * az összehasonlításba, ez a teszt azonnal bukik.
     *
     * A tét nem elméleti: a TransactionState::nextRepeatCount() ezzel keresi
     * meg az előző bejegyzést ugyanarra a párra. Ha nem találná meg, az
     * ismétlés-számláló minden értesítésnél 1-ről indulna, és a diagnosztika
     * csendben megszűnne működni.
     */
    public function testItMatchesThePairEvenWhenEveryOtherFieldDiffers(): void
    {
        $entry = new IpnLogEntry(
            receivedAt: new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
            transactionId: '99844942',
            status: TransactionStatus::Finished,
            outcome: IpnOutcome::Rejected,
            repeatCount: 7,
        );

        self::assertTrue($entry->matches('99844942', TransactionStatus::Finished));
    }

    public function testItRejectsAMissingReceivedAtRatherThanDefaultingToNow(): void
    {
        $array = self::entry()->toArray();
        unset($array['receivedAt']);

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('receivedAt');

        IpnLogEntry::fromArray($array);
    }

    public function testItRejectsAnUnknownStatus(): void
    {
        $array = self::entry()->toArray();
        $array['status'] = 'ELKÉPZELT';

        $this->expectException(MissingDetailsException::class);

        IpnLogEntry::fromArray($array);
    }
}
