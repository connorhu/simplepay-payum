<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Action;

use CodeConjure\SimplePay\Exception\SignatureException;
use CodeConjure\SimplePay\Exception\UnexpectedResponseException;
use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Action\ResolveIpnAction;
use CodeConjure\SimplePayPayum\Request\ResolveSimplePayIpn;
use CodeConjure\SimplePayPayum\Tests\Unit\ApiFactory;
use CodeConjure\SimplePayPayum\Tests\Unit\IpnFixture;
use Http\Mock\Client as MockHttpClient;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;

final class ResolveIpnActionTest extends TestCase
{
    private function action(): ResolveIpnAction
    {
        $action = new ResolveIpnAction();
        $action->setApi(ApiFactory::create(new MockHttpClient()));

        return $action;
    }

    public function testItVerifiesAndParsesWithoutAModel(): void
    {
        $body = IpnFixture::finishedBody();
        $request = new ResolveSimplePayIpn($body, IpnFixture::sign($body));

        $this->action()->execute($request);

        $message = $request->getMessage();

        self::assertSame('101010515680292482600', $message->orderRef);
        self::assertSame(TransactionStatus::Finished, $message->status);
    }

    public function testTheTransactionIdArrivesAsAJsonNumberAndBecomesAString(): void
    {
        $body = IpnFixture::finishedBody();

        self::assertStringContainsString('"transactionId":99844942', $body);

        $request = new ResolveSimplePayIpn($body, IpnFixture::sign($body));
        $this->action()->execute($request);

        self::assertSame('99844942', $request->getMessage()->transactionId);
    }

    public function testTheIpnCarriesNoAmountSoNoAmountCheckIsPossibleFromIt(): void
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(IpnFixture::finishedBody(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('total', $decoded);
        self::assertArrayNotHasKey('currency', $decoded);
    }

    public function testAForgedSignatureIsRejected(): void
    {
        $request = new ResolveSimplePayIpn(IpnFixture::finishedBody(), 'hamis-aláírás');

        $this->expectException(SignatureException::class);

        $this->action()->execute($request);
    }

    public function testAnEmptySignatureIsRejected(): void
    {
        $request = new ResolveSimplePayIpn(IpnFixture::finishedBody(), '');

        $this->expectException(SignatureException::class);

        $this->action()->execute($request);
    }

    public function testAForeignMerchantIsRejected(): void
    {
        $body = IpnFixture::bodyWith(['merchant' => 'IDEGEN']);
        $request = new ResolveSimplePayIpn($body, IpnFixture::sign($body));

        $this->expectException(UnexpectedResponseException::class);

        $this->action()->execute($request);
    }

    public function testAskingForTheMessageBeforeResolutionIsAProgrammingError(): void
    {
        $request = new ResolveSimplePayIpn('{}', 'x');

        $this->expectException(\LogicException::class);

        $request->getMessage();
    }

    public function testItSupportsOnlyItsOwnRequest(): void
    {
        $action = $this->action();

        self::assertTrue($action->supports(new ResolveSimplePayIpn('{}', 'x')));
        self::assertFalse($action->supports(new Notify(new \ArrayObject([]))));
    }

    public function testExecuteRefusesARequestOfTheWrongType(): void
    {
        // Az őrök a futásidejű kikényszerítés; a supports() csak a deklaratív
        // szerződés, amit a Payum kérdez meg. Ha egy hívó megkerüli a
        // supports()-ot, az execute()-nak akkor is el kell utasítania.
        $this->expectException(RequestNotSupportedException::class);

        $this->action()->execute(new Notify(new \ArrayObject([])));
    }

    public function testItFailsLoudlyWhenNoApiWasInjected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Api');

        $body = IpnFixture::finishedBody();

        new ResolveIpnAction()->execute(new ResolveSimplePayIpn($body, IpnFixture::sign($body)));
    }
}
