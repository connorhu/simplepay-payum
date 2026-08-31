<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Action;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Action\SyncAction;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Exception\TransactionNotFoundException;
use CodeConjure\SimplePayPayum\Tests\Unit\ApiFactory;
use CodeConjure\SimplePayPayum\Tests\Unit\Model\StartDataFactory;
use Http\Mock\Client as MockHttpClient;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Sync;
use PHPUnit\Framework\TestCase;

final class SyncActionTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private static function queryBody(array $overrides = []): string
    {
        return json_encode([
            'totalCount' => 1,
            'transactions' => [array_merge([
                'merchant' => ApiFactory::MERCHANT,
                'orderRef' => 'EZ-2026-0042-17-1',
                'transactionId' => 509007600,
                'status' => 'FINISHED',
                'total' => 1000,
                'remainingTotal' => 1000,
                'currency' => 'HUF',
                'method' => 'CARD',
            ], $overrides)],
        ], \JSON_THROW_ON_ERROR);
    }

    private function action(MockHttpClient $httpClient): SyncAction
    {
        $action = new SyncAction();
        $action->setApi(ApiFactory::create($httpClient));

        return $action;
    }

    public function testItQueriesByTransactionIdWhenOneIsKnown(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::queryBody()));

        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => ['transactionId' => '509007600', 'status' => 'INIT'],
        ]);

        $this->action($httpClient)->execute(new Sync($model));

        $sent = json_decode((string) $httpClient->getLastRequest()?->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($sent);
        self::assertSame(['509007600'], $sent['transactionIds']);
        self::assertTrue($sent['detailed']);
        self::assertSame(TransactionStatus::Finished, Details::fromModel($model)->state()->status);
    }

    public function testItFallsBackToTheOrderRefWhenNoTransactionIdIsStored(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::queryBody()));

        $model = new ArrayObject([Details::REQUEST_KEY => StartDataFactory::array()]);

        $this->action($httpClient)->execute(new Sync($model));

        $sent = json_decode((string) $httpClient->getLastRequest()?->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($sent);
        self::assertSame(['EZ-2026-0042-17-1'], $sent['orderRefs']);
    }

    public function testItRecordsTheCheckTimestampAndTheAmounts(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::queryBody()));

        $model = new ArrayObject([Details::REQUEST_KEY => StartDataFactory::array()]);

        $this->action($httpClient)->execute(new Sync($model));

        $state = Details::fromModel($model)->state();

        self::assertNotNull($state->lastCheckedAt);
        self::assertSame(1000, $state->remainingTotal);
        self::assertSame('509007600', $state->transactionId);
    }

    public function testADisallowedTransitionDoesNotOverwriteTheStoredStatus(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::queryBody(['status' => 'INIT'])));

        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => ['transactionId' => '509007600', 'status' => 'CANCELLED'],
        ]);

        $this->action($httpClient)->execute(new Sync($model));

        self::assertSame(TransactionStatus::Cancelled, Details::fromModel($model)->state()->status);
    }

    public function testAnUnknownTransactionIsLoudNotSilentlyIgnored(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(
            json_encode(['totalCount' => 0, 'transactions' => []], \JSON_THROW_ON_ERROR),
        ));

        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => ['transactionId' => '509007600'],
        ]);

        $this->expectException(TransactionNotFoundException::class);
        $this->expectExceptionMessage('509007600');

        $this->action($httpClient)->execute(new Sync($model));
    }

    public function testItRefusesToSyncAPaymentThatWasNeverPrepared(): void
    {
        $this->expectException(MissingDetailsException::class);

        $this->action(new MockHttpClient())->execute(new Sync(new ArrayObject([])));
    }

    public function testItSupportsOnlySyncRequestsWithAnArrayAccessModel(): void
    {
        $action = $this->action(new MockHttpClient());

        self::assertTrue($action->supports(new Sync(new ArrayObject([]))));
        self::assertFalse($action->supports(new Capture(new ArrayObject([]))));
        self::assertFalse($action->supports(new Sync(new \stdClass())));
    }

    public function testExecuteRefusesARequestOfTheWrongType(): void
    {
        $this->expectException(RequestNotSupportedException::class);

        $this->action(new MockHttpClient())->execute(new Capture(new ArrayObject([])));
    }

    public function testExecuteRefusesASyncRequestWhoseModelIsNotArrayAccess(): void
    {
        $this->expectException(RequestNotSupportedException::class);

        $this->action(new MockHttpClient())->execute(new Sync(new \stdClass()));
    }

    public function testItFailsLoudlyWhenNoApiWasInjected(): void
    {
        $action = new SyncAction();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Api');

        $action->execute(new Sync(new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
        ])));
    }
}
