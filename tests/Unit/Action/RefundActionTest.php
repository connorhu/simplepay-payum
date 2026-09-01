<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Action;

use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Tests\Unit\ApiFactory;
use CodeConjure\SimplePayPayum\Tests\Unit\Model\StartDataFactory;
use Http\Mock\Client as MockHttpClient;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Refund;
use PHPUnit\Framework\TestCase;

final class RefundActionTest extends TestCase
{
    private static function refundBody(): string
    {
        return json_encode([
            'merchant' => ApiFactory::MERCHANT,
            'orderRef' => 'EZ-2026-0042-17-1',
            'transactionId' => 509007600,
            'refundTransactionId' => 509007601,
            'refundTotal' => 500,
            'remainingTotal' => 500,
            'currency' => 'HUF',
            'salt' => 'só',
        ], \JSON_THROW_ON_ERROR);
    }

    private function action(MockHttpClient $httpClient): RefundAction
    {
        $action = new RefundAction();
        $action->setApi(ApiFactory::create($httpClient));

        return $action;
    }

    /** @return ArrayObject<string, mixed> */
    private static function model(int $amount): ArrayObject
    {
        return new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => ['transactionId' => '509007600', 'status' => 'FINISHED'],
            Details::REFUND_KEY => ['amount' => $amount],
        ]);
    }

    public function testItRefundsTheAmountWrittenIntoTheDetails(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::refundBody()));

        $model = self::model(500);

        $this->action($httpClient)->execute(new Refund($model));

        $sent = json_decode((string) $httpClient->getLastRequest()?->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($sent);
        self::assertSame('500', $sent['refundTotal']);
        self::assertSame('HUF', $sent['currency']);
        self::assertSame('509007600', $sent['transactionId']);
    }

    public function testItRecordsTheRefundResultInTheState(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::refundBody()));

        $model = self::model(500);

        $this->action($httpClient)->execute(new Refund($model));

        $state = Details::fromModel($model)->state();

        self::assertSame('509007601', $state->refundTransactionId);
        self::assertSame(500, $state->refundTotal);
        self::assertSame(500, $state->remainingTotal);
    }

    public function testItDoesNotChangeTheStatusBecauseOnlyTheIpnOrAQueryDecidesThat(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::refundBody()));

        $model = self::model(500);

        $this->action($httpClient)->execute(new Refund($model));

        self::assertSame('FINISHED', Details::fromModel($model)->state()->status?->value);
    }

    /**
     * A `simplepay_refund` névtér egyszer elfogyasztandó parancs: az első
     * dispatch után törlődnie kell, különben egy retry vagy dupla kattintás
     * ugyanazt az összeget olvasná ki újra, és egy második, valódi jóváírást
     * indítana a SimplePay-nél. A második dispatch tehát PONTOSAN úgy bukik,
     * mint egy sosem beírt összeg.
     */
    public function testASecondDispatchOnTheSameModelDoesNotReissueTheRefund(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::refundBody()));

        $model = self::model(500);

        $this->action($httpClient)->execute(new Refund($model));

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage(Details::REFUND_KEY);

        try {
            $this->action($httpClient)->execute(new Refund($model));
        } finally {
            self::assertCount(1, $httpClient->getRequests(), 'A második dispatch nem küldhet második /refund kérést.');
        }
    }

    public function testItRefusesToDefaultTheAmountToTheFullTotal(): void
    {
        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => ['transactionId' => '509007600', 'status' => 'FINISHED'],
        ]);

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage(Details::REFUND_KEY);

        $this->action(new MockHttpClient())->execute(new Refund($model));
    }

    public function testItRefusesToRefundAPaymentThatWasNeverStarted(): void
    {
        $model = new ArrayObject([Details::REFUND_KEY => ['amount' => 500]]);

        $this->expectException(MissingDetailsException::class);

        $this->action(new MockHttpClient())->execute(new Refund($model));
    }

    public function testItSupportsOnlyRefundRequestsWithAnArrayAccessModel(): void
    {
        $action = $this->action(new MockHttpClient());

        self::assertTrue($action->supports(new Refund(new ArrayObject([]))));
        self::assertFalse($action->supports(new Capture(new ArrayObject([]))));
        self::assertFalse($action->supports(new Refund(new \stdClass())));
    }

    public function testExecuteRefusesARequestOfTheWrongType(): void
    {
        $this->expectException(RequestNotSupportedException::class);

        $this->action(new MockHttpClient())->execute(new Capture(new ArrayObject([])));
    }

    public function testExecuteRefusesARefundRequestWhoseModelIsNotArrayAccess(): void
    {
        $this->expectException(RequestNotSupportedException::class);

        $this->action(new MockHttpClient())->execute(new Refund(new \stdClass()));
    }

    public function testItFailsLoudlyWhenNoApiWasInjected(): void
    {
        $action = new RefundAction();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Api');

        $action->execute(new Refund(new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => ['transactionId' => '509007600', 'status' => 'FINISHED'],
            Details::REFUND_KEY => ['amount' => 500],
        ])));
    }
}
