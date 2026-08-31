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

    public function testItRejectsAnUnknownStatus(): void
    {
        $array = self::entry()->toArray();
        $array['status'] = 'ELKÉPZELT';

        $this->expectException(MissingDetailsException::class);

        IpnLogEntry::fromArray($array);
    }
}
