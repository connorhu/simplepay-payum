<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests;

use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ApiTest extends TestCase
{
    private Psr17Factory $factory;
    private Client $mockClient;

    protected function setUp(): void
    {
        $this->factory    = new Psr17Factory();
        $this->mockClient = new Client();
    }

    private function makeApi(bool $sandbox = true): Api
    {
        return new Api(
            merchantId:     'MERCHANTID001',
            secretKey:      'FxDa5w314kLlNseq2sKuVwaqZshZT5d6',
            sandbox:        $sandbox,
            httpClient:     $this->mockClient,
            requestFactory: $this->factory,
            streamFactory:  $this->factory,
        );
    }

    public function testVerifySignatureAcceptsValidSignature(): void
    {
        $api  = $this->makeApi();
        $body = '{"test":"data"}';
        $sig  = base64_encode(hash_hmac('sha256', $body, 'FxDa5w314kLlNseq2sKuVwaqZshZT5d6', true));

        self::assertTrue($api->verifySignature($body, $sig));
    }

    public function testVerifySignatureRejectsInvalidSignature(): void
    {
        $api = $this->makeApi();
        self::assertFalse($api->verifySignature('{"test":"data"}', 'invalidsig'));
    }

    public function testStartPaymentSendsSignedRequest(): void
    {
        $this->mockClient->addResponse(new Response(200, [], json_encode([
            'transactionId' => '12345',
            'paymentUrl'    => 'https://sandbox.simplepay.hu/pay/12345',
            'status'        => 'INIT',
        ])));

        $api    = $this->makeApi();
        $result = $api->startPayment(['orderRef' => 'ORDER-1', 'total' => 5000, 'currency' => 'HUF']);

        $request = $this->mockClient->getLastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertStringContainsString('/payment/v2/start', (string) $request->getUri());
        self::assertTrue($request->hasHeader('Signature'));
        self::assertSame('12345', $result['transactionId']);
    }

    public function testStartPaymentThrowsOnNon2xxResponse(): void
    {
        $this->mockClient->addResponse(new Response(500, [], 'Internal Server Error'));

        $this->expectException(\RuntimeException::class);
        $this->makeApi()->startPayment(['orderRef' => 'ORDER-1']);
    }

    public function testGetMerchantId(): void
    {
        self::assertSame('MERCHANTID001', $this->makeApi()->getMerchantId());
    }

    public function testSandboxUrlUsed(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '{}'));
        $this->makeApi(sandbox: true)->startPayment([]);
        self::assertStringContainsString('sandbox.simplepay.hu', (string) $this->mockClient->getLastRequest()->getUri());
    }

    public function testProductionUrlUsed(): void
    {
        $this->mockClient->addResponse(new Response(200, [], '{}'));
        $this->makeApi(sandbox: false)->startPayment([]);
        self::assertStringContainsString('simplepay.otpbank.hu', (string) $this->mockClient->getLastRequest()->getUri());
    }
}
