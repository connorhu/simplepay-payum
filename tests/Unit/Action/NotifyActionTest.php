<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Action;

use CodeConjure\SimplePay\Exception\SignatureException;
use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\MissingSignatureHeaderException;
use CodeConjure\SimplePayPayum\Model\IpnOutcome;
use CodeConjure\SimplePayPayum\Request\GetHttpRequestWithHeaders;
use CodeConjure\SimplePayPayum\Tests\Unit\ApiFactory;
use CodeConjure\SimplePayPayum\Tests\Unit\IpnFixture;
use CodeConjure\SimplePayPayum\TransitionGuard;
use Http\Mock\Client as MockHttpClient;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayInterface;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class NotifyActionTest extends TestCase
{
    /**
     * A gateway egyetlen dolga itt: kitölteni a GetHttpRequestWithHeaders-t,
     * ahogy azt a Symfony-híd tenné.
     *
     * @param array<string, list<string|null>> $headers
     */
    private function gateway(string $body, array $headers): GatewayInterface
    {
        $gateway = $this->createStub(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(
            static function (mixed $request) use ($body, $headers): void {
                if ($request instanceof GetHttpRequestWithHeaders) {
                    $request->content = $body;
                    $request->headers = $headers;
                    $request->method = 'POST';
                }
            },
        );

        return $gateway;
    }

    /** @param array<string, list<string|null>>|null $headers */
    private function action(string $body, ?array $headers = null): NotifyAction
    {
        $action = new NotifyAction();
        $action->setApi(ApiFactory::create(new MockHttpClient()));
        $action->setGateway($this->gateway($body, $headers ?? ['signature' => [IpnFixture::sign($body)]]));

        return $action;
    }

    /** @param ArrayObject<string, mixed> $model */
    private function notify(NotifyAction $action, ArrayObject $model): HttpResponse
    {
        try {
            $action->execute(new Notify($model));
        } catch (HttpResponse $reply) {
            return $reply;
        }

        self::fail('A NotifyAction mindig HttpResponse reply-t kell dobjon hitelesített üzenetre.');
    }

    public function testItAppliesTheStatusAndAnswersWithASignedConfirmation(): void
    {
        $body = IpnFixture::finishedBody();
        // SZÁNDÉKOSAN nincs transactionId a fixture-ben: az applied() útnak
        // ÍRNIA kell a tranzakció-azonosítót, nem csak megőriznie egy
        // korábbit. Ha a fixture eleve az érkező üzenettel egyező
        // transactionId-t tartalmazná, az alábbi assertSame() akkor is
        // zöld maradna, ha az applied() sosem írná ki a mezőt.
        $model = new ArrayObject([Details::STATE_KEY => ['status' => 'INIT']]);

        $reply = $this->notify($this->action($body), $model);

        self::assertSame(200, $reply->getStatusCode());
        self::assertStringContainsString('"receiveDate"', $reply->getContent());
        // A visszaigazolás a beérkezett törzs, a záró kapcsos zárójel helyén
        // a "receiveDate" mezővel folytatva — tehát a törzs mínusz az utolsó
        // karaktere egyezik a válasz megfelelő hosszúságú elejével.
        self::assertSame(substr($body, 0, -1), substr($reply->getContent(), 0, strlen($body) - 1));
        self::assertArrayHasKey('Signature', $reply->getHeaders());
        self::assertNotSame('', $reply->getHeaders()['Signature']);

        $state = Details::fromModel($model)->state();

        self::assertSame(TransactionStatus::Finished, $state->status);
        self::assertSame('99844942', $state->transactionId);
        self::assertCount(1, $state->ipnLog);
        self::assertSame(IpnOutcome::Applied, $state->ipnLog[0]->outcome);
    }

    public function testTheConfirmationSignatureCoversTheBodyItActuallySends(): void
    {
        $body = IpnFixture::finishedBody();

        $reply = $this->notify($this->action($body), new ArrayObject([]));

        self::assertSame(
            IpnFixture::sign($reply->getContent()),
            $reply->getHeaders()['Signature'],
        );
    }

    public function testADuplicateIsStillConfirmedButDoesNotRewriteTheState(): void
    {
        $body = IpnFixture::finishedBody();
        $model = new ArrayObject([
            Details::STATE_KEY => [
                'transactionId' => '99844942',
                'status' => 'FINISHED',
                'finishDate' => '2019-09-09T14:46:18+02:00',
            ],
        ]);

        $reply = $this->notify($this->action($body), $model);

        self::assertSame(200, $reply->getStatusCode());

        $state = Details::fromModel($model)->state();

        self::assertSame(TransactionStatus::Finished, $state->status);
        self::assertSame(IpnOutcome::Duplicate, $state->ipnLog[0]->outcome);
        self::assertSame(1, $state->ipnLog[0]->repeatCount);
    }

    public function testARepeatedDuplicateIncrementsTheDiagnosticCounter(): void
    {
        $body = IpnFixture::finishedBody();
        $model = new ArrayObject([Details::STATE_KEY => ['transactionId' => '99844942', 'status' => 'FINISHED']]);

        $this->notify($this->action($body), $model);
        $this->notify($this->action($body), $model);
        $this->notify($this->action($body), $model);

        $log = Details::fromModel($model)->state()->ipnLog;

        self::assertCount(3, $log);
        self::assertSame(3, $log[2]->repeatCount);
    }

    public function testADisallowedTransitionIsStillConfirmedButNotApplied(): void
    {
        $body = IpnFixture::bodyWith(['status' => 'INIT']);
        $model = new ArrayObject([Details::STATE_KEY => ['transactionId' => '99844942', 'status' => 'CANCELLED']]);

        $reply = $this->notify($this->action($body), $model);

        self::assertSame(200, $reply->getStatusCode());

        $state = Details::fromModel($model)->state();

        self::assertSame(TransactionStatus::Cancelled, $state->status, 'A tiltott átmenet nem írhatja felül az állapotot.');
        self::assertSame(IpnOutcome::Rejected, $state->ipnLog[0]->outcome);
    }

    public function testAForgedSignatureProducesNoConfirmationAtAll(): void
    {
        $body = IpnFixture::finishedBody();
        $action = $this->action($body, ['signature' => ['hamis']]);

        $this->expectException(SignatureException::class);

        $action->execute(new Notify(new ArrayObject([])));
    }

    public function testAMissingSignatureHeaderIsLoudNotADefault(): void
    {
        $action = $this->action(IpnFixture::finishedBody(), []);

        $this->expectException(MissingSignatureHeaderException::class);

        $action->execute(new Notify(new ArrayObject([])));
    }

    public function testAnEmptySignatureHeaderIsAlsoLoud(): void
    {
        $action = $this->action(IpnFixture::finishedBody(), ['signature' => ['']]);

        $this->expectException(MissingSignatureHeaderException::class);

        $action->execute(new Notify(new ArrayObject([])));
    }

    public function testTheSignatureHeaderIsFoundRegardlessOfLetterCase(): void
    {
        $body = IpnFixture::finishedBody();
        $action = $this->action($body, ['Signature' => [IpnFixture::sign($body)]]);

        self::assertSame(200, $this->notify($action, new ArrayObject([]))->getStatusCode());
    }

    public function testItAlsoRecordsTheMethodAndTheDatesFromTheNotification(): void
    {
        $model = new ArrayObject([]);

        $this->notify($this->action(IpnFixture::finishedBody()), $model);

        $state = Details::fromModel($model)->state();

        self::assertSame('CARD', $state->method?->value);
        self::assertSame('2019-09-09T14:41:13+02:00', $state->paymentDate?->format(\DateTimeInterface::ATOM));
        self::assertSame('2019-09-09T14:46:18+02:00', $state->finishDate?->format(\DateTimeInterface::ATOM));
    }

    /**
     * A `merchant` az `Api`-ból, nem a modellből vagy az üzenetből érkezik —
     * ez az a hely, ahol az `Api::$merchant` ténylegesen hasznosul: több
     * merchant-fiókot használó alkalmazásban enélkül a napló nem mondaná
     * meg, melyik fiókra érkezett az értesítés.
     */
    public function testTheLogContextCarriesTheMerchantFromTheApi(): void
    {
        $body = IpnFixture::finishedBody();

        $logger = $this->createStub(LoggerInterface::class);
        /** @var list<array<string, mixed>> $contexts */
        $contexts = [];
        $logger->method('info')->willReturnCallback(
            static function (string $message, array $context) use (&$contexts): void {
                $contexts[] = $context;
            },
        );

        $action = new NotifyAction(new TransitionGuard(), $logger);
        $action->setApi(ApiFactory::create(new MockHttpClient()));
        $action->setGateway($this->gateway($body, ['signature' => [IpnFixture::sign($body)]]));

        $this->notify($action, new ArrayObject([]));

        self::assertSame(ApiFactory::MERCHANT, $contexts[0]['merchant'] ?? null);
    }

    public function testItSupportsOnlyNotifyRequestsWithAnArrayAccessModel(): void
    {
        $action = $this->action(IpnFixture::finishedBody());

        self::assertTrue($action->supports(new Notify(new ArrayObject([]))));
        self::assertFalse($action->supports(new Capture(new ArrayObject([]))));
        self::assertFalse($action->supports(new Notify(new \stdClass())));
    }

    public function testExecuteRefusesARequestOfTheWrongType(): void
    {
        $this->expectException(RequestNotSupportedException::class);

        $this->action(IpnFixture::finishedBody())->execute(new Capture(new ArrayObject([])));
    }

    public function testExecuteRefusesANotifyRequestWhoseModelIsNotArrayAccess(): void
    {
        $this->expectException(RequestNotSupportedException::class);

        $this->action(IpnFixture::finishedBody())->execute(new Notify(new \stdClass()));
    }

    public function testItFailsLoudlyWhenNoApiWasInjected(): void
    {
        $action = new NotifyAction();
        $action->setGateway($this->gateway(IpnFixture::finishedBody(), ['signature' => ['x']]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Api');

        $action->execute(new Notify(new ArrayObject([])));
    }
}
