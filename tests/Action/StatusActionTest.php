<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Action;

use CodeConjure\SimplePayPayum\Action\StatusAction;
use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\TestCase;

class StatusActionTest extends TestCase
{
    private StatusAction $action;

    protected function setUp(): void
    {
        $this->action = new StatusAction();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statusProvider')]
    public function testMapsStatus(string $simplePayStatus, string $expectedMethod): void
    {
        $request = new GetHumanStatus(new \ArrayObject(['status' => $simplePayStatus]));
        $this->action->execute($request);

        self::assertTrue(
            $request->$expectedMethod(),
            sprintf('Expected %s() for SimplePay status "%s"', $expectedMethod, $simplePayStatus)
        );
    }

    public static function statusProvider(): array
    {
        return [
            ['COMPLETE', 'isCaptured'],
            ['WAITING', 'isPending'],
            ['IN_PAYMENT', 'isPending'],
            ['CANCELLED', 'isCanceled'],
            ['TIMEOUT', 'isExpired'],
            ['REFUND', 'isRefunded'],
            ['REFUND_PARTIAL', 'isRefunded'],
            ['FRAUD', 'isFailed'],
            ['CARD_DECLINED', 'isFailed'],
            ['ERROR', 'isFailed'],
        ];
    }

    public function testEmptyStatusIsNew(): void
    {
        $request = new GetHumanStatus(new \ArrayObject([]));
        $this->action->execute($request);
        self::assertTrue($request->isNew());
    }

    public function testUnknownStatusIsUnknown(): void
    {
        $request = new GetHumanStatus(new \ArrayObject(['status' => 'SOMETHING_WEIRD']));
        $this->action->execute($request);
        self::assertTrue($request->isUnknown());
    }

    public function testSupportsGetStatusWithArrayAccess(): void
    {
        self::assertTrue($this->action->supports(new GetHumanStatus(new \ArrayObject())));
    }
}
