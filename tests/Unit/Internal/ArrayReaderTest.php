<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Internal;

use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Internal\ArrayReader;
use PHPUnit\Framework\TestCase;

final class ArrayReaderTest extends TestCase
{
    public function testStringReadsAString(): void
    {
        self::assertSame('ABC', ArrayReader::string(['orderRef' => 'ABC'], 'orderRef', 'simplepay_request'));
    }

    public function testStringAcceptsTheLiteralZeroBecauseItIsAValidValue(): void
    {
        self::assertSame('0', ArrayReader::string(['orderRef' => '0'], 'orderRef', 'simplepay_request'));
    }

    public function testStringRejectsAnEmptyString(): void
    {
        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('"orderRef"');

        ArrayReader::string(['orderRef' => ''], 'orderRef', 'simplepay_request');
    }

    public function testStringRejectsAnIntegerRatherThanCastingIt(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::string(['orderRef' => 42], 'orderRef', 'simplepay_request');
    }

    public function testStringNamesTheContextInTheMessage(): void
    {
        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('simplepay_request');

        ArrayReader::string([], 'orderRef', 'simplepay_request');
    }

    public function testIntReadsAnIntegerAndAcceptsZero(): void
    {
        self::assertSame(0, ArrayReader::int(['total' => 0], 'total', 'simplepay_request'));
    }

    public function testIntRejectsANumericString(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::int(['total' => '1000'], 'total', 'simplepay_request');
    }

    public function testNullableStringReturnsNullForMissingAndForEmpty(): void
    {
        self::assertNull(ArrayReader::nullableString([], 'transactionId'));
        self::assertNull(ArrayReader::nullableString(['transactionId' => ''], 'transactionId'));
        self::assertSame('T1', ArrayReader::nullableString(['transactionId' => 'T1'], 'transactionId'));
    }

    public function testNullableIntKeepsZeroAndDropsMissing(): void
    {
        self::assertSame(0, ArrayReader::nullableInt(['attempt' => 0], 'attempt'));
        self::assertNull(ArrayReader::nullableInt([], 'attempt'));
    }

    public function testMapReadsAStringKeyedArray(): void
    {
        self::assertSame(
            ['name' => 'Teszt Elek'],
            ArrayReader::map(['invoice' => ['name' => 'Teszt Elek']], 'invoice', 'simplepay_request'),
        );
    }

    public function testMapRejectsAList(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::map(['invoice' => ['Teszt Elek']], 'invoice', 'simplepay_request');
    }

    public function testStringListReadsAListOfStrings(): void
    {
        self::assertSame(
            ['CARD'],
            ArrayReader::stringList(['methods' => ['CARD']], 'methods', 'simplepay_request'),
        );
    }

    public function testStringListRejectsAnEmptyList(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::stringList(['methods' => []], 'methods', 'simplepay_request');
    }

    public function testMapListReturnsAnEmptyListWhenTheKeyIsAbsent(): void
    {
        self::assertSame([], ArrayReader::mapList([], 'ipnLog'));
    }

    public function testMapListRejectsAPresentKeyThatIsNotAList(): void
    {
        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('ipnLog');

        ArrayReader::mapList(['ipnLog' => 'szemét'], 'ipnLog');
    }

    public function testMapListRejectsAMapWhereAListWasExpected(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::mapList(['ipnLog' => ['receivedAt' => 'ma']], 'ipnLog');
    }

    public function testMapListRejectsANonArrayElementRatherThanSkippingIt(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::mapList(['ipnLog' => [['a' => 1], 'szemét']], 'ipnLog');
    }

    public function testMapListStillDropsNonStringKeysInsideAnElement(): void
    {
        self::assertSame(
            [['receivedAt' => 'ma']],
            ArrayReader::mapList(['ipnLog' => [['receivedAt' => 'ma', 0 => 'kiesik']]], 'ipnLog'),
        );
    }

    public function testNullableDateTimeParsesAnAtomTimestamp(): void
    {
        $parsed = ArrayReader::nullableDateTime(['timeout' => '2026-08-31T10:00:00+02:00'], 'timeout');

        self::assertInstanceOf(\DateTimeImmutable::class, $parsed);
        self::assertSame('2026-08-31T10:00:00+02:00', $parsed->format(\DateTimeInterface::ATOM));
    }

    public function testNullableDateTimeRejectsAnUnparseableValueRatherThanReturningNull(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::nullableDateTime(['timeout' => 'tegnap délután'], 'timeout');
    }
}
