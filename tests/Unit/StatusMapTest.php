<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\StatusMap;
use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusMapTest extends TestCase
{
    /** @return iterable<string, array{TransactionStatus, string}> */
    public static function statuses(): iterable
    {
        yield 'INIT függőben' => [TransactionStatus::Init, 'isPending'];
        yield 'INPAYMENT függőben' => [TransactionStatus::InPayment, 'isPending'];
        yield 'INFRAUD függőben' => [TransactionStatus::InFraud, 'isPending'];
        yield 'AUTHORIZED felhatalmazva' => [TransactionStatus::Authorized, 'isAuthorized'];
        yield 'FINISHED beszedve' => [TransactionStatus::Finished, 'isCaptured'];
        yield 'CANCELLED megszakítva' => [TransactionStatus::Cancelled, 'isCanceled'];
        yield 'TIMEOUT megszakítva' => [TransactionStatus::Timeout, 'isCanceled'];
        yield 'NOTAUTHORIZED sikertelen' => [TransactionStatus::NotAuthorized, 'isFailed'];
        yield 'FRAUD sikertelen' => [TransactionStatus::Fraud, 'isFailed'];
        yield 'REVERSED jóváírva' => [TransactionStatus::Reversed, 'isRefunded'];
        yield 'REFUND jóváírva' => [TransactionStatus::Refund, 'isRefunded'];
    }

    #[DataProvider('statuses')]
    public function testEveryTransactionStatusMapsToAPayumStatus(
        TransactionStatus $status,
        string $expectedMarker,
    ): void {
        $request = new GetHumanStatus(new \ArrayObject([]));

        StatusMap::apply($request, $status);

        self::assertTrue($request->{$expectedMarker}(), sprintf(
            'A(z) %s státusznak a %s() jelzőt kellene igazra állítania, de a kapott érték %s.',
            $status->value,
            $expectedMarker,
            var_export($request->getValue(), true),
        ));
    }

    public function testAllElevenStatusesAreCovered(): void
    {
        self::assertCount(
            count(TransactionStatus::cases()),
            iterator_to_array(self::statuses()),
            'A StatusMap tesztje minden TransactionStatus esetet le kell fedjen.',
        );
    }

    public function testAnAbsentStatusIsNewNotUnknown(): void
    {
        $request = new GetHumanStatus(new \ArrayObject([]));

        StatusMap::apply($request, null);

        self::assertTrue($request->isNew());
    }
}
