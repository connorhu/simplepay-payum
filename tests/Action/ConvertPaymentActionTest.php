<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Action;

use CodeConjure\SimplePayPayum\Action\ConvertPaymentAction;
use Payum\Core\Model\Payment;
use Payum\Core\Request\Convert;
use PHPUnit\Framework\TestCase;

class ConvertPaymentActionTest extends TestCase
{
    private ConvertPaymentAction $action;

    protected function setUp(): void
    {
        $this->action = new ConvertPaymentAction();
    }

    public function testSupportsConvertWithPaymentSource(): void
    {
        self::assertTrue($this->action->supports(new Convert(new Payment(), 'array')));
    }

    public function testDoesNotSupportNonPaymentSource(): void
    {
        self::assertFalse($this->action->supports(new Convert(new \stdClass(), 'array')));
    }

    public function testConvertsPaymentFields(): void
    {
        $payment = new Payment();
        $payment->setNumber('ORDER-42');
        $payment->setCurrencyCode('HUF');
        $payment->setTotalAmount(5000);
        $payment->setClientEmail('test@example.com');

        $request = new Convert($payment, 'array');
        $this->action->execute($request);
        $result = $request->getResult();

        self::assertSame('ORDER-42', $result['orderRef']);
        self::assertSame('HUF', $result['currency']);
        self::assertSame(5000, $result['total']);
        self::assertSame('test@example.com', $result['customerEmail']);
        self::assertSame('HU', $result['language']);
        self::assertSame(['CARD'], $result['methods']);
    }

    public function testTimeoutIsApproximately30MinutesInFuture(): void
    {
        $payment = new Payment();
        $request = new Convert($payment, 'array');
        $this->action->execute($request);
        $result = $request->getResult();

        self::assertArrayHasKey('timeout', $result);
        $timeout = new \DateTimeImmutable($result['timeout']);
        self::assertGreaterThan(new \DateTimeImmutable('+29 minutes'), $timeout);
        self::assertLessThan(new \DateTimeImmutable('+31 minutes'), $timeout);
    }

    public function testDoesNotOverrideExistingLanguage(): void
    {
        $payment = new Payment();
        $payment->setDetails(['language' => 'EN']);
        $request = new Convert($payment, 'array');
        $this->action->execute($request);
        self::assertSame('EN', $request->getResult()['language']);
    }
}
