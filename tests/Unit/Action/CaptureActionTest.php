<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Action;

use CodeConjure\SimplePay\Exception\RequestException;
use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Tests\Unit\ApiFactory;
use CodeConjure\SimplePayPayum\Tests\Unit\Model\StartDataFactory;
use Http\Mock\Client as MockHttpClient;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;

final class CaptureActionTest extends TestCase
{
    private const string PAYMENT_URL = 'https://sb-checkout.simplepay.hu/trx/abc';

    private static function startResponseBody(): string
    {
        return json_encode([
            'merchant' => ApiFactory::MERCHANT,
            'orderRef' => 'EZ-2026-0042-17-1',
            'transactionId' => 509007600,
            'paymentUrl' => self::PAYMENT_URL,
            'total' => 1000,
            'currency' => 'HUF',
            'timeout' => '2099-01-01T10:00:00+01:00',
            'salt' => 'só',
        ], \JSON_THROW_ON_ERROR);
    }

    private function action(MockHttpClient $httpClient): CaptureAction
    {
        $action = new CaptureAction();
        $action->setApi(ApiFactory::create($httpClient));

        return $action;
    }

    public function testItStartsATransactionAndRedirectsToTheHostedPage(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::startResponseBody()));

        $model = new ArrayObject([Details::REQUEST_KEY => StartDataFactory::array()]);

        try {
            $this->action($httpClient)->execute(new Capture($model));
            self::fail('A CaptureAction HttpRedirect reply-t kell dobjon.');
        } catch (HttpRedirect $redirect) {
            self::assertSame(self::PAYMENT_URL, $redirect->getUrl());
        }

        $state = Details::fromModel($model)->state();

        self::assertSame('509007600', $state->transactionId);
        self::assertSame(self::PAYMENT_URL, $state->paymentUrl);
        self::assertSame('INIT', $state->status?->value);
        self::assertSame(1, $state->attempt);
    }

    public function testItSendsTheDifferentiatedUrlsAndNeverTheSingleUrlField(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::startResponseBody()));

        $this->executeCapture($httpClient, new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
        ]));

        $sent = json_decode((string) $httpClient->getLastRequest()?->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($sent);
        self::assertArrayHasKey('urls', $sent);
        self::assertArrayNotHasKey('url', $sent);
        self::assertSame('1000', $sent['total']);
    }

    public function testItReusesALiveTransactionInsteadOfStartingASecondOne(): void
    {
        $httpClient = new MockHttpClient();

        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => [
                'transactionId' => 'T1',
                'status' => 'INIT',
                'paymentUrl' => self::PAYMENT_URL,
                'timeout' => '2099-01-01T10:00:00+01:00',
                'attempt' => 1,
            ],
        ]);

        try {
            $this->action($httpClient)->execute(new Capture($model));
            self::fail('A CaptureAction HttpRedirect reply-t kell dobjon.');
        } catch (HttpRedirect $redirect) {
            self::assertSame(self::PAYMENT_URL, $redirect->getUrl());
        }

        self::assertCount(0, $httpClient->getRequests(), 'Élő tranzakcióhoz nem szabad új /start kérést küldeni.');
    }

    public function testAnExpiredTransactionStartsAFreshOne(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::startResponseBody()));

        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => [
                'transactionId' => 'RÉGI',
                'status' => 'INIT',
                'paymentUrl' => 'https://sb-checkout.simplepay.hu/trx/régi',
                'timeout' => '2020-01-01T10:00:00+01:00',
                'attempt' => 1,
            ],
        ]);

        $this->executeCapture($httpClient, $model);

        self::assertNotNull($httpClient->getLastRequest());
        self::assertSame('509007600', Details::fromModel($model)->state()->transactionId);
    }

    public function testTheAttemptCounterAdvancesOnlyWhenATransactionIsActuallyStarted(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::startResponseBody()));

        $request = StartDataFactory::array();
        $request['attempt'] = 3;

        $model = new ArrayObject([Details::REQUEST_KEY => $request]);

        $this->executeCapture($httpClient, $model);

        self::assertSame(3, Details::fromModel($model)->state()->attempt);
    }

    public function testTheIpnLogSurvivesANewTransaction(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::startResponseBody()));

        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => [
                'status' => 'TIMEOUT',
                'ipnLog' => [[
                    'receivedAt' => '2026-08-31T12:00:00+02:00',
                    'transactionId' => 'RÉGI',
                    'status' => 'TIMEOUT',
                    'outcome' => 'applied',
                    'repeatCount' => 1,
                ]],
            ],
        ]);

        $this->executeCapture($httpClient, $model);

        self::assertCount(1, Details::fromModel($model)->state()->ipnLog);
    }

    public function testARejectedRequestRecordsTheErrorCodesBeforeRethrowing(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(
            json_encode(['errorCodes' => [5321]], \JSON_THROW_ON_ERROR),
        ));

        $model = new ArrayObject([Details::REQUEST_KEY => StartDataFactory::array()]);

        try {
            $this->action($httpClient)->execute(new Capture($model));
            self::fail('A SimplePay elutasítását tovább kell dobni.');
        } catch (RequestException $exception) {
            self::assertSame([5321], $exception->codes());
        }

        self::assertSame([5321], Details::fromModel($model)->state()->lastErrorCodes);
    }

    public function testItRefusesToStartWithoutTheRequestNamespace(): void
    {
        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage(Details::REQUEST_KEY);

        $this->action(new MockHttpClient())->execute(new Capture(new ArrayObject([])));
    }

    public function testItSupportsOnlyCaptureRequestsWithAnArrayAccessModel(): void
    {
        $action = $this->action(new MockHttpClient());

        self::assertTrue($action->supports(new Capture(new ArrayObject([]))));
        self::assertFalse($action->supports(new Notify(new ArrayObject([]))));
        self::assertFalse($action->supports(new Capture(new \stdClass())));
    }

    public function testExecuteRefusesARequestOfTheWrongType(): void
    {
        $this->expectException(RequestNotSupportedException::class);

        $this->action(new MockHttpClient())->execute(new Notify(new ArrayObject([])));
    }

    public function testExecuteRefusesACaptureRequestWhoseModelIsNotArrayAccess(): void
    {
        $this->expectException(RequestNotSupportedException::class);

        $this->action(new MockHttpClient())->execute(new Capture(new \stdClass()));
    }

    /** @param ArrayObject<string, mixed> $model */
    private function executeCapture(MockHttpClient $httpClient, ArrayObject $model): void
    {
        try {
            $this->action($httpClient)->execute(new Capture($model));
        } catch (HttpRedirect) {
            // A sikeres capture mindig átirányítással végződik.
        }
    }
}
