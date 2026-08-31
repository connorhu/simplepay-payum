<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SimplePayPayum\Tests\Unit\Model\StartDataFactory;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;

final class DetailsTest extends TestCase
{
    public function testItReadsTheStartDataFromItsOwnNamespace(): void
    {
        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
        ]);

        self::assertSame('EZ-2026-0042-17-1', Details::fromModel($model)->startData()->orderRef);
    }

    public function testItRefusesToGuessWhenTheStartDataIsAbsent(): void
    {
        $details = Details::fromModel(new ArrayObject([]));

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage(Details::REQUEST_KEY);

        $details->startData();
    }

    public function testAnAbsentStateReadsAsAnEmptyStateNotAnError(): void
    {
        $state = Details::fromModel(new ArrayObject([]))->state();

        self::assertNull($state->transactionId);
        self::assertSame(0, $state->attempt);
    }

    public function testANamespaceStoredAsAnArrayObjectIsReadRatherThanSeenAsAbsent(): void
    {
        // A `fromModel()` bármilyen \ArrayAccess modellt elfogad, és egy hívó
        // beágyazott \ArrayObject-et is tárolhat névtér-értékként. Kicsomagolás
        // nélkül ez csendben üres állapotként olvasódna.
        $model = new ArrayObject([
            Details::STATE_KEY => new \ArrayObject(['transactionId' => 'T1', 'status' => 'FINISHED']),
        ]);

        $state = Details::fromModel($model)->state();

        self::assertSame('T1', $state->transactionId);
        self::assertSame('FINISHED', $state->status?->value);
    }

    public function testWritingTheStateReplacesTheWholeNamespace(): void
    {
        $model = new ArrayObject([
            Details::STATE_KEY => ['transactionId' => 'RÉGI', 'paymentUrl' => 'https://régi'],
        ]);

        Details::fromModel($model)->writeState(new TransactionState(
            transactionId: 'ÚJ',
            status: TransactionStatus::Init,
        ));

        self::assertSame(
            ['transactionId' => 'ÚJ', 'status' => 'INIT'],
            $model[Details::STATE_KEY],
        );
    }

    public function testWritingTheStateLeavesTheRequestNamespaceUntouched(): void
    {
        $model = new ArrayObject([Details::REQUEST_KEY => StartDataFactory::array()]);

        Details::fromModel($model)->writeState(new TransactionState(transactionId: 'T1'));

        self::assertSame(StartDataFactory::array(), $model[Details::REQUEST_KEY]);
    }

    public function testItReadsTheRefundAmount(): void
    {
        $model = new ArrayObject([Details::REFUND_KEY => ['amount' => 500]]);

        self::assertSame(500, Details::fromModel($model)->refundAmount());
    }

    public function testItRefusesToDefaultTheRefundAmountToTheFullTotal(): void
    {
        $details = Details::fromModel(new ArrayObject([Details::REQUEST_KEY => StartDataFactory::array()]));

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage(Details::REFUND_KEY);

        $details->refundAmount();
    }

    public function testClearRefundRemovesTheNamespaceEntirely(): void
    {
        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::REFUND_KEY => ['amount' => 500],
        ]);

        Details::fromModel($model)->clearRefund();

        self::assertFalse($model->offsetExists(Details::REFUND_KEY));

        $this->expectException(MissingDetailsException::class);

        Details::fromModel($model)->refundAmount();
    }

    public function testClearRefundOnAnAlreadyAbsentNamespaceIsNotAnError(): void
    {
        $model = new ArrayObject([]);

        Details::fromModel($model)->clearRefund();

        self::assertFalse($model->offsetExists(Details::REFUND_KEY));
    }

    public function testItWritesTheStartDataBack(): void
    {
        $model = new ArrayObject([]);

        Details::fromModel($model)->writeStartData(StartDataFactory::create());

        self::assertSame(StartDataFactory::array(), $model[Details::REQUEST_KEY]);
    }
}
