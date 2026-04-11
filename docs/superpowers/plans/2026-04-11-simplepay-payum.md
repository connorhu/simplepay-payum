# SimplePay Payum Gateway Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `codeconjure/simplepay-payum`, an OTP SimplePay v2 Payum gateway with Symfony bundle, covering Capture + Status + Refund + IPN flows.

**Architecture:** Standalone Composer library following Payum's gateway pattern: `Api` handles HTTP communication and HMAC-SHA256 signing via PSR-18/PSR-17 interfaces; five `Action` classes implement Payum's `ActionInterface`; `SimplePayGatewayFactory` wires everything together via `populateConfig()`; `SimplePayBundle` provides Symfony DI auto-configuration for use with PayumBundle.

**Tech Stack:** PHP 8.1+, payum/core ^1.7, psr/http-client + psr/http-factory (PSR-18/17), php-http/discovery, PHPUnit 11, nyholm/psr7 + php-http/mock-client (tests only)

**Working directory:** `/Users/connor/Work/opensource/simplepay-payum`

**PHP binary:** `~/.phpbrew/php/php-8.4.4/bin/php`

**Test command:** `~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit --no-coverage`

---

## File map

| Státusz | Fájl | Felelősség |
|---|---|---|
| Create | `composer.json` | Package definition, autoloading, dependencies |
| Create | `phpunit.xml` | Test runner configuration |
| Create | `.gitignore` | Ignore vendor/, composer.lock, cache |
| Create | `src/Api.php` | HTTP client + HMAC sign/verify |
| Create | `src/Action/ConvertPaymentAction.php` | Payum PaymentInterface → SimplePay array |
| Create | `src/Action/StatusAction.php` | SimplePay status strings → Payum status marks |
| Create | `src/Action/CaptureAction.php` | Payment initiation + return URL handling |
| Create | `src/Action/NotifyAction.php` | IPN POST handler |
| Create | `src/Action/RefundAction.php` | Refund API call |
| Create | `src/SimplePayGatewayFactory.php` | Registers all actions + Api in Payum |
| Create | `src/Bundle/SimplePayBundle.php` | Symfony bundle class |
| Create | `src/Bundle/DependencyInjection/SimplePayExtension.php` | Loads services.xml |
| Create | `src/Bundle/Resources/config/services.xml` | Gateway factory service definition |
| Create | `tests/ApiTest.php` | Api unit tests |
| Create | `tests/Action/ConvertPaymentActionTest.php` | ConvertPaymentAction tests |
| Create | `tests/Action/StatusActionTest.php` | StatusAction tests |
| Create | `tests/Action/CaptureActionTest.php` | CaptureAction tests |
| Create | `tests/Action/NotifyActionTest.php` | NotifyAction tests |
| Create | `tests/Action/RefundActionTest.php` | RefundAction tests |
| Create | `tests/SimplePayGatewayFactoryTest.php` | Factory config test |
| Create | `README.md` | Installation and configuration guide |

---

### Task 1: Project scaffold

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `.gitignore`

- [ ] **Step 1: Hozd létre a `composer.json`-t**

```json
{
    "name": "codeconjure/simplepay-payum",
    "description": "OTP SimplePay v2 Payum gateway with Symfony bundle",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.1",
        "payum/core": "^1.7",
        "psr/http-client": "^1.0",
        "psr/http-factory": "^1.0",
        "php-http/discovery": "^1.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "php-http/mock-client": "^1.6",
        "nyholm/psr7": "^1.0",
        "symfony/framework-bundle": "^7.0"
    },
    "suggest": {
        "symfony/http-client": "Symfony HTTP client PSR-18 adapter",
        "nyholm/psr7": "Lightweight PSR-7 implementation"
    },
    "autoload": {
        "psr-4": {
            "CodeConjure\\SimplePayPayum\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "CodeConjure\\SimplePayPayum\\Tests\\": "tests/"
        }
    },
    "extra": {
        "symfony": {
            "bundles": {
                "CodeConjure\\SimplePayPayum\\Bundle\\SimplePayBundle": ["all"]
            }
        }
    }
}
```

- [ ] **Step 2: Hozd létre a `phpunit.xml`-t**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="default">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Hozd létre a `.gitignore`-t**

```
/vendor/
/composer.lock
/.phpunit.cache/
```

- [ ] **Step 4: Futtasd a composer install-t**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php /usr/local/bin/composer install
```

Várható: sikeres telepítés, `vendor/` létrejön.

- [ ] **Step 5: Hozd létre a könyvtárstruktúrát**

```bash
mkdir -p /Users/connor/Work/opensource/simplepay-payum/src/Action
mkdir -p /Users/connor/Work/opensource/simplepay-payum/src/Bundle/DependencyInjection
mkdir -p /Users/connor/Work/opensource/simplepay-payum/src/Bundle/Resources/config
mkdir -p /Users/connor/Work/opensource/simplepay-payum/tests/Action
```

- [ ] **Step 6: Commit**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
git add composer.json phpunit.xml .gitignore && \
git commit -m "chore: project scaffold"
```

---

### Task 2: Api osztály

**Files:**
- Create: `tests/ApiTest.php`
- Create: `src/Api.php`

Az `Api` osztály PSR-18 (`ClientInterface`) és PSR-17 (`RequestFactoryInterface`, `StreamFactoryInterface`) interfészeket használ. A `sign()` metódus publikus, hogy a tesztek és az `Action`-ök könnyen ellenőrizhessék a HMAC-ot.

- [ ] **Step 1: Írj failing teszteket — `tests/ApiTest.php`**

```php
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
```

- [ ] **Step 2: Futtasd — várható: FAIL**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/ApiTest.php --no-coverage 2>&1 | tail -5
```

Várható: `Error: Class "CodeConjure\SimplePayPayum\Api" not found`

- [ ] **Step 3: Implementáld a `src/Api.php`-t**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Api
{
    private const SANDBOX_URL    = 'https://sandbox.simplepay.hu/payment/v2/';
    private const PRODUCTION_URL = 'https://simplepay.otpbank.hu/payment/v2/';

    public function __construct(
        private readonly string $merchantId,
        private readonly string $secretKey,
        private readonly bool $sandbox,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    public function startPayment(array $data): array
    {
        return $this->post('start', $data);
    }

    public function queryPayment(array $data): array
    {
        return $this->post('query', $data);
    }

    public function refundPayment(array $data): array
    {
        return $this->post('refund', $data);
    }

    public function verifySignature(string $body, string $signature): bool
    {
        return hash_equals($this->sign($body), $signature);
    }

    public function sign(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, $this->secretKey, true));
    }

    private function post(string $endpoint, array $data): array
    {
        $body    = json_encode($data, JSON_THROW_ON_ERROR);
        $request = $this->requestFactory
            ->createRequest('POST', $this->getBaseUrl() . $endpoint)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Signature', $this->sign($body))
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf(
                'SimplePay API error %d: %s',
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ));
        }

        $responseBody = (string) $response->getBody();
        if ($responseBody === '' || $responseBody === '{}') {
            return [];
        }

        return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    }

    private function getBaseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }
}
```

- [ ] **Step 4: Futtasd — várható: PASS**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/ApiTest.php --no-coverage 2>&1 | tail -5
```

Várható: `OK (7 tests, ...)`

- [ ] **Step 5: Commit**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
git add src/Api.php tests/ApiTest.php && \
git commit -m "feat: Api class with HMAC signing and HTTP client"
```

---

### Task 3: ConvertPaymentAction

**Files:**
- Create: `tests/Action/ConvertPaymentActionTest.php`
- Create: `src/Action/ConvertPaymentAction.php`

Payum `PaymentInterface`-ből SimplePay-kompatibilis array-t épít. Az `url` mezőt az alkalmazás állítja be a payment details-ben; a `language`, `methods`, `timeout` mezőkhöz alapértelmezett értékeket ad, ha még nincsenek beállítva.

- [ ] **Step 1: Írj failing teszteket — `tests/Action/ConvertPaymentActionTest.php`**

```php
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
```

- [ ] **Step 2: Futtasd — várható: FAIL**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/Action/ConvertPaymentActionTest.php --no-coverage 2>&1 | tail -5
```

Várható: `Error: Class "CodeConjure\SimplePayPayum\Action\ConvertPaymentAction" not found`

- [ ] **Step 3: Implementáld a `src/Action/ConvertPaymentAction.php`-t**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;

class ConvertPaymentAction implements ActionInterface
{
    public function execute($request): void
    {
        /** @var Convert $request */
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        $details['orderRef']      = $payment->getNumber();
        $details['currency']      = $payment->getCurrencyCode();
        $details['total']         = $payment->getTotalAmount();
        $details['customerEmail'] = $payment->getClientEmail();
        $details['language']      = $details['language'] ?? 'HU';
        $details['methods']       = $details['methods'] ?? ['CARD'];
        $details['timeout']       = $details['timeout'] ?? (new \DateTimeImmutable('+30 minutes'))->format(\DateTimeInterface::ATOM);

        $request->setResult((array) $details);
    }

    public function supports($request): bool
    {
        return $request instanceof Convert
            && $request->getSource() instanceof PaymentInterface
            && $request->getTo() === 'array';
    }
}
```

- [ ] **Step 4: Futtasd — várható: PASS**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/Action/ConvertPaymentActionTest.php --no-coverage 2>&1 | tail -5
```

Várható: `OK (5 tests, ...)`

- [ ] **Step 5: Commit**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
git add src/Action/ConvertPaymentAction.php tests/Action/ConvertPaymentActionTest.php && \
git commit -m "feat: ConvertPaymentAction"
```

---

### Task 4: StatusAction

**Files:**
- Create: `tests/Action/StatusActionTest.php`
- Create: `src/Action/StatusAction.php`

- [ ] **Step 1: Írj failing teszteket — `tests/Action/StatusActionTest.php`**

```php
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

    /** @dataProvider statusProvider */
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
```

- [ ] **Step 2: Futtasd — várható: FAIL**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/Action/StatusActionTest.php --no-coverage 2>&1 | tail -5
```

Várható: `Error: Class "CodeConjure\SimplePayPayum\Action\StatusAction" not found`

- [ ] **Step 3: Implementáld a `src/Action/StatusAction.php`-t**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;

class StatusAction implements ActionInterface
{
    public function execute($request): void
    {
        /** @var GetStatusInterface $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (!isset($model['status']) || $model['status'] === '') {
            $request->markNew();
            return;
        }

        match ($model['status']) {
            'COMPLETE'                            => $request->markCaptured(),
            'WAITING', 'IN_PAYMENT'               => $request->markPending(),
            'CANCELLED'                           => $request->markCanceled(),
            'TIMEOUT'                             => $request->markExpired(),
            'REFUND', 'REFUND_PARTIAL'            => $request->markRefunded(),
            'FRAUD', 'CARD_DECLINED', 'ERROR'     => $request->markFailed(),
            default                               => $request->markUnknown(),
        };
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface
            && $request->getModel() instanceof \ArrayAccess;
    }
}
```

- [ ] **Step 4: Futtasd — várható: PASS**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/Action/StatusActionTest.php --no-coverage 2>&1 | tail -5
```

Várható: `OK (13 tests, ...)`

- [ ] **Step 5: Commit**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
git add src/Action/StatusAction.php tests/Action/StatusActionTest.php && \
git commit -m "feat: StatusAction with SimplePay → Payum status mapping"
```

---

### Task 5: CaptureAction

**Files:**
- Create: `tests/Action/CaptureActionTest.php`
- Create: `src/Action/CaptureAction.php`

A `CaptureAction` implementálja az `ApiAwareInterface`-t (a `setApi()` metódus ellenőrzi, hogy `Api` példányt kap) és a `GatewayAwareInterface`-t (a `GetHttpRequest` sub-request végrehajtásához). Az első hívásnál (nincs `transactionId`) `HttpRedirect` reply-jal irányít át a SimplePay oldalra. A visszatérésnél (van `transactionId`) ellenőrzi az `r`+`s` paramétereket és lekérdezi a tranzakció státuszát.

- [ ] **Step 1: Írj failing teszteket — `tests/Action/CaptureActionTest.php`**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Action;

use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHttpRequest;
use PHPUnit\Framework\TestCase;

class CaptureActionTest extends TestCase
{
    private function makeApi(array $startResponse = [], array $queryResponse = []): Api
    {
        $factory    = new Psr17Factory();
        $mockClient = new Client();
        if ($startResponse) {
            $mockClient->addResponse(new Response(200, [], json_encode($startResponse)));
        }
        if ($queryResponse) {
            $mockClient->addResponse(new Response(200, [], json_encode($queryResponse)));
        }
        return new Api('MERCHANT', 'SECRET', true, $mockClient, $factory, $factory);
    }

    private function makeGateway(array $queryParams = []): GatewayInterface
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(function ($request) use ($queryParams): void {
            if ($request instanceof GetHttpRequest) {
                $request->query = $queryParams;
            }
        });
        return $gateway;
    }

    public function testRedirectsToPaymentUrlOnFirstCall(): void
    {
        $api = $this->makeApi(startResponse: [
            'transactionId' => 'TXN-1',
            'paymentUrl'    => 'https://sandbox.simplepay.hu/pay/TXN-1',
        ]);

        $action = new CaptureAction();
        $action->setApi($api);
        $action->setGateway($this->makeGateway());

        $model = new ArrayObject(['orderRef' => 'ORDER-1', 'total' => 5000, 'currency' => 'HUF']);

        try {
            $action->execute(new Capture($model));
            self::fail('Expected HttpRedirect reply');
        } catch (HttpRedirect $reply) {
            self::assertStringContainsString('TXN-1', $reply->getUrl());
        }
    }

    public function testSavesTransactionIdToModel(): void
    {
        $api = $this->makeApi(startResponse: [
            'transactionId' => 'TXN-42',
            'paymentUrl'    => 'https://sandbox.simplepay.hu/pay/TXN-42',
        ]);

        $action = new CaptureAction();
        $action->setApi($api);
        $action->setGateway($this->makeGateway());

        $model = new ArrayObject(['orderRef' => 'ORDER-1', 'total' => 5000]);
        try {
            $action->execute(new Capture($model));
        } catch (HttpRedirect) {}

        self::assertSame('TXN-42', $model['transactionId']);
    }

    public function testHandlesReturnWithValidSignature(): void
    {
        $secretKey = 'SECRET';
        $rParam    = base64_encode(json_encode(['t' => 'TXN-1', 'e' => 'SUCCESS']));
        $sParam    = base64_encode(hash_hmac('sha256', $rParam, $secretKey, true));

        $api = $this->makeApi(queryResponse: ['status' => 'COMPLETE']);
        $action = new CaptureAction();
        $action->setApi($api);
        $action->setGateway($this->makeGateway(['r' => $rParam, 's' => $sParam]));

        $model = new ArrayObject(['orderRef' => 'ORDER-1', 'transactionId' => 'TXN-1']);
        $action->execute(new Capture($model));

        self::assertSame('COMPLETE', $model['status']);
    }

    public function testSetsStatusToFraudOnInvalidSignature(): void
    {
        $api = $this->makeApi();
        $action = new CaptureAction();
        $action->setApi($api);
        $action->setGateway($this->makeGateway(['r' => 'abc', 's' => 'invalidsig']));

        $model = new ArrayObject(['orderRef' => 'ORDER-1', 'transactionId' => 'TXN-1']);
        $action->execute(new Capture($model));

        self::assertSame('FRAUD', $model['status']);
    }
}
```

- [ ] **Step 2: Futtasd — várható: FAIL**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/Action/CaptureActionTest.php --no-coverage 2>&1 | tail -5
```

Várható: `Error: Class "CodeConjure\SimplePayPayum\Action\CaptureAction" not found`

- [ ] **Step 3: Implementáld a `src/Action/CaptureAction.php`-t**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePayPayum\Api;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHttpRequest;

class CaptureAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function setApi($api): void
    {
        if (!$api instanceof Api) {
            throw new UnsupportedApiException(sprintf('Expected %s instance.', Api::class));
        }
        $this->api = $api;
    }

    public function execute($request): void
    {
        /** @var Capture $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (isset($model['transactionId'])) {
            $this->handleReturn($model);
            return;
        }

        $this->initiatePayment($model);
    }

    private function initiatePayment(ArrayObject $model): void
    {
        $data = array_merge((array) $model, [
            'salt'       => bin2hex(random_bytes(16)),
            'merchant'   => $this->api->getMerchantId(),
            'sdkVersion' => 'CodeConjure_SimplePayPayum/1.0.0',
        ]);

        $result = $this->api->startPayment($data);

        $model['transactionId'] = $result['transactionId'];
        $model['paymentUrl']    = $result['paymentUrl'];

        throw new HttpRedirect($result['paymentUrl']);
    }

    private function handleReturn(ArrayObject $model): void
    {
        $httpRequest = new GetHttpRequest();
        $this->gateway->execute($httpRequest);

        $r = $httpRequest->query['r'] ?? null;
        $s = $httpRequest->query['s'] ?? null;

        if (!$r || !$s || !$this->api->verifySignature($r, $s)) {
            $model['status'] = 'FRAUD';
            return;
        }

        $returnData    = json_decode(base64_decode($r), true) ?? [];
        $transactionId = $returnData['t'] ?? $model['transactionId'];

        $result          = $this->api->queryPayment([
            'salt'          => bin2hex(random_bytes(16)),
            'merchant'      => $this->api->getMerchantId(),
            'transactionId' => $transactionId,
            'orderRef'      => $model['orderRef'],
        ]);
        $model['status'] = $result['status'] ?? 'UNKNOWN';
    }

    public function supports($request): bool
    {
        return $request instanceof Capture
            && $request->getModel() instanceof \ArrayAccess;
    }
}
```

- [ ] **Step 4: Futtasd — várható: PASS**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/Action/CaptureActionTest.php --no-coverage 2>&1 | tail -5
```

Várható: `OK (4 tests, ...)`

- [ ] **Step 5: Commit**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
git add src/Action/CaptureAction.php tests/Action/CaptureActionTest.php && \
git commit -m "feat: CaptureAction - payment initiation and return handling"
```

---

### Task 6: NotifyAction

**Files:**
- Create: `tests/Action/NotifyActionTest.php`
- Create: `src/Action/NotifyAction.php`

Az IPN POST-ot kezeli. A `GetHttpRequest` sub-request-ből olvassa ki a raw body-t (`content`) és a `Signature` headert. Érvényes aláírás esetén `queryPayment`-et hív, majd `HttpResponse` reply-t dob `200`-zal. Érvénytelen aláírás esetén `400`-zal tér vissza.

- [ ] **Step 1: Írj failing teszteket — `tests/Action/NotifyActionTest.php`**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Action;

use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;

class NotifyActionTest extends TestCase
{
    private function makeApi(array $queryResponse = []): Api
    {
        $factory    = new Psr17Factory();
        $mockClient = new Client();
        if ($queryResponse) {
            $mockClient->addResponse(new Response(200, [], json_encode($queryResponse)));
        }
        return new Api('MERCHANT', 'SECRET', true, $mockClient, $factory, $factory);
    }

    private function makeGateway(string $body, string $signature): GatewayInterface
    {
        $gateway = $this->createMock(GatewayInterface::class);
        $gateway->method('execute')->willReturnCallback(function ($request) use ($body, $signature): void {
            if ($request instanceof GetHttpRequest) {
                $request->content = $body;
                $request->headers = ['signature' => [$signature]];
            }
        });
        return $gateway;
    }

    public function testReturns200AndUpdatesStatusForValidSignature(): void
    {
        $body      = json_encode(['transactionId' => 'TXN-1', 'orderRef' => 'ORDER-1']);
        $secretKey = 'SECRET';
        $signature = base64_encode(hash_hmac('sha256', $body, $secretKey, true));

        $action = new NotifyAction();
        $action->setApi($this->makeApi(queryResponse: ['status' => 'COMPLETE']));
        $action->setGateway($this->makeGateway($body, $signature));

        $model  = new ArrayObject(['orderRef' => 'ORDER-1', 'transactionId' => 'TXN-1']);
        $notify = new Notify($model);

        try {
            $action->execute($notify);
            self::fail('Expected HttpResponse reply');
        } catch (HttpResponse $reply) {
            self::assertSame(200, $reply->getStatusCode());
        }

        self::assertSame('COMPLETE', $model['status']);
    }

    public function testReturns400ForInvalidSignature(): void
    {
        $body   = json_encode(['transactionId' => 'TXN-1']);
        $action = new NotifyAction();
        $action->setApi($this->makeApi());
        $action->setGateway($this->makeGateway($body, 'invalidsig'));

        try {
            $action->execute(new Notify(new ArrayObject([])));
            self::fail('Expected HttpResponse reply');
        } catch (HttpResponse $reply) {
            self::assertSame(400, $reply->getStatusCode());
        }
    }
}
```

- [ ] **Step 2: Futtasd — várható: FAIL**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/Action/NotifyActionTest.php --no-coverage 2>&1 | tail -5
```

Várható: `Error: Class "CodeConjure\SimplePayPayum\Action\NotifyAction" not found`

- [ ] **Step 3: Implementáld a `src/Action/NotifyAction.php`-t**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePayPayum\Api;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;

class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function setApi($api): void
    {
        if (!$api instanceof Api) {
            throw new UnsupportedApiException(sprintf('Expected %s instance.', Api::class));
        }
        $this->api = $api;
    }

    public function execute($request): void
    {
        /** @var Notify $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $httpRequest = new GetHttpRequest();
        $this->gateway->execute($httpRequest);

        $body      = $httpRequest->content ?? '';
        $signature = $httpRequest->headers['signature'][0] ?? '';

        if (!$this->api->verifySignature($body, $signature)) {
            throw new HttpResponse('', 400);
        }

        $data  = json_decode($body, true) ?? [];
        $model = ArrayObject::ensureArrayObject($request->getModel());

        $result          = $this->api->queryPayment([
            'salt'          => bin2hex(random_bytes(16)),
            'merchant'      => $this->api->getMerchantId(),
            'transactionId' => $data['transactionId'] ?? $model['transactionId'],
            'orderRef'      => $data['orderRef'] ?? $model['orderRef'],
        ]);
        $model['status'] = $result['status'] ?? $data['status'] ?? 'UNKNOWN';

        throw new HttpResponse('OK', 200);
    }

    public function supports($request): bool
    {
        return $request instanceof Notify
            && $request->getModel() instanceof \ArrayAccess;
    }
}
```

- [ ] **Step 4: Futtasd — várható: PASS**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/Action/NotifyActionTest.php --no-coverage 2>&1 | tail -5
```

Várható: `OK (2 tests, ...)`

- [ ] **Step 5: Commit**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
git add src/Action/NotifyAction.php tests/Action/NotifyActionTest.php && \
git commit -m "feat: NotifyAction - IPN handler"
```

---

### Task 7: RefundAction

**Files:**
- Create: `tests/Action/RefundActionTest.php`
- Create: `src/Action/RefundAction.php`

- [ ] **Step 1: Írj failing teszteket — `tests/Action/RefundActionTest.php`**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Action;

use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Request\Refund;
use PHPUnit\Framework\TestCase;

class RefundActionTest extends TestCase
{
    private function makeApi(int $statusCode, array $responseBody): Api
    {
        $factory    = new Psr17Factory();
        $mockClient = new Client();
        $mockClient->addResponse(new Response($statusCode, [], json_encode($responseBody)));
        return new Api('MERCHANT', 'SECRET', true, $mockClient, $factory, $factory);
    }

    public function testRefundsPaymentAndSetsStatus(): void
    {
        $action = new RefundAction();
        $action->setApi($this->makeApi(200, ['status' => 'REFUND', 'refundedAmount' => 5000]));
        $action->setGateway($this->createMock(GatewayInterface::class));

        $model = new ArrayObject([
            'transactionId' => 'TXN-1',
            'orderRef'      => 'ORDER-1',
            'total'         => 5000,
            'currency'      => 'HUF',
        ]);
        $action->execute(new Refund($model));

        self::assertSame('REFUND', $model['status']);
    }

    public function testThrowsOnApiError(): void
    {
        $action = new RefundAction();
        $action->setApi($this->makeApi(500, ['message' => 'error']));
        $action->setGateway($this->createMock(GatewayInterface::class));

        $this->expectException(\RuntimeException::class);
        $action->execute(new Refund(new ArrayObject(['transactionId' => 'TXN-1', 'total' => 5000])));
    }
}
```

- [ ] **Step 2: Futtasd — várható: FAIL**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/Action/RefundActionTest.php --no-coverage 2>&1 | tail -5
```

Várható: `Error: Class "CodeConjure\SimplePayPayum\Action\RefundAction" not found`

- [ ] **Step 3: Implementáld a `src/Action/RefundAction.php`-t**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePayPayum\Api;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Refund;

class RefundAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    /** @var Api */
    protected $api;

    public function setApi($api): void
    {
        if (!$api instanceof Api) {
            throw new UnsupportedApiException(sprintf('Expected %s instance.', Api::class));
        }
        $this->api = $api;
    }

    public function execute($request): void
    {
        /** @var Refund $request */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        $result = $this->api->refundPayment([
            'salt'          => bin2hex(random_bytes(16)),
            'merchant'      => $this->api->getMerchantId(),
            'orderRef'      => $model['orderRef'],
            'transactionId' => $model['transactionId'],
            'currency'      => $model['currency'] ?? 'HUF',
            'refundTotal'   => $model['total'],
        ]);

        $model['status'] = $result['status'] ?? 'REFUND';
    }

    public function supports($request): bool
    {
        return $request instanceof Refund
            && $request->getModel() instanceof \ArrayAccess;
    }
}
```

- [ ] **Step 4: Futtasd — várható: PASS**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/Action/RefundActionTest.php --no-coverage 2>&1 | tail -5
```

Várható: `OK (2 tests, ...)`

- [ ] **Step 5: Commit**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
git add src/Action/RefundAction.php tests/Action/RefundActionTest.php && \
git commit -m "feat: RefundAction"
```

---

### Task 8: SimplePayGatewayFactory

**Files:**
- Create: `tests/SimplePayGatewayFactoryTest.php`
- Create: `src/SimplePayGatewayFactory.php`

A `populateConfig()` metódusban regisztrál minden action-t és létrehozza az `Api` példányt. A tesztet Reflection segítségével, a `populateConfig` közvetlen hívásával végezzük — így nem kell a teljes Payum infrastruktúra (CoreGatewayFactory, token storage, stb.).

- [ ] **Step 1: Írj failing tesztet — `tests/SimplePayGatewayFactoryTest.php`**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests;

use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Action\ConvertPaymentAction;
use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Action\StatusAction;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\SimplePayGatewayFactory;
use Http\Mock\Client;
use Nyholm\Psr7\Factory\Psr17Factory;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;

class SimplePayGatewayFactoryTest extends TestCase
{
    private function invokePopulateConfig(array $userConfig): ArrayObject
    {
        $factory = new SimplePayGatewayFactory();
        $config  = new ArrayObject($userConfig);

        $method = new \ReflectionMethod(SimplePayGatewayFactory::class, 'populateConfig');
        $method->setAccessible(true);
        $method->invoke($factory, $config);

        return $config;
    }

    public function testFactoryName(): void
    {
        $config = $this->invokePopulateConfig([
            'merchant_id' => 'M', 'secret_key' => 'S',
            'http_client' => new Client(), 'request_factory' => new Psr17Factory(), 'stream_factory' => new Psr17Factory(),
        ]);
        self::assertSame('simplepay', $config['payum.factory_name']);
        self::assertSame('OTP SimplePay', $config['payum.factory_title']);
    }

    public function testRegistersAllActions(): void
    {
        $config = $this->invokePopulateConfig([
            'merchant_id' => 'M', 'secret_key' => 'S',
            'http_client' => new Client(), 'request_factory' => new Psr17Factory(), 'stream_factory' => new Psr17Factory(),
        ]);

        self::assertInstanceOf(CaptureAction::class, $config['payum.action.capture']);
        self::assertInstanceOf(NotifyAction::class, $config['payum.action.notify']);
        self::assertInstanceOf(RefundAction::class, $config['payum.action.refund']);
        self::assertInstanceOf(StatusAction::class, $config['payum.action.status']);
        self::assertInstanceOf(ConvertPaymentAction::class, $config['payum.action.convert_payment']);
    }

    public function testCreatesApiInstance(): void
    {
        $config = $this->invokePopulateConfig([
            'merchant_id' => 'MERCHANT', 'secret_key' => 'SECRET', 'sandbox' => true,
            'http_client' => new Client(), 'request_factory' => new Psr17Factory(), 'stream_factory' => new Psr17Factory(),
        ]);

        self::assertInstanceOf(Api::class, $config['payum.api']);
        self::assertSame('MERCHANT', $config['payum.api']->getMerchantId());
    }

    public function testSandboxDefaultsFalse(): void
    {
        $config = $this->invokePopulateConfig([
            'merchant_id' => 'M', 'secret_key' => 'S',
            'http_client' => new Client(), 'request_factory' => new Psr17Factory(), 'stream_factory' => new Psr17Factory(),
        ]);
        self::assertFalse($config['sandbox']);
    }
}
```

- [ ] **Step 2: Futtasd — várható: FAIL**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/SimplePayGatewayFactoryTest.php --no-coverage 2>&1 | tail -5
```

Várható: `Error: Class "CodeConjure\SimplePayPayum\SimplePayGatewayFactory" not found`

- [ ] **Step 3: Implementáld a `src/SimplePayGatewayFactory.php`-t**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Action\ConvertPaymentAction;
use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Action\StatusAction;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;

class SimplePayGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'sandbox'             => false,
            'payum.factory_name'  => 'simplepay',
            'payum.factory_title' => 'OTP SimplePay',

            'payum.action.capture'         => new CaptureAction(),
            'payum.action.notify'          => new NotifyAction(),
            'payum.action.refund'          => new RefundAction(),
            'payum.action.status'          => new StatusAction(),
            'payum.action.convert_payment' => new ConvertPaymentAction(),
        ]);

        if (!$config->offsetExists('payum.api')) {
            $config['payum.api'] = new Api(
                merchantId:     $config['merchant_id'],
                secretKey:      $config['secret_key'],
                sandbox:        (bool) $config['sandbox'],
                httpClient:     $config['http_client'] ?? Psr18ClientDiscovery::find(),
                requestFactory: $config['request_factory'] ?? Psr17FactoryDiscovery::findRequestFactory(),
                streamFactory:  $config['stream_factory'] ?? Psr17FactoryDiscovery::findStreamFactory(),
            );
        }
    }
}
```

- [ ] **Step 4: Futtasd — várható: PASS**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit tests/SimplePayGatewayFactoryTest.php --no-coverage 2>&1 | tail -5
```

Várható: `OK (4 tests, ...)`

- [ ] **Step 5: Futtasd a teljes suite-t**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
~/.phpbrew/php/php-8.4.4/bin/php vendor/bin/phpunit --no-coverage 2>&1 | tail -5
```

Várható: minden teszt PASS.

- [ ] **Step 6: Commit**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
git add src/SimplePayGatewayFactory.php tests/SimplePayGatewayFactoryTest.php && \
git commit -m "feat: SimplePayGatewayFactory"
```

---

### Task 9: Symfony Bundle

**Files:**
- Create: `src/Bundle/SimplePayBundle.php`
- Create: `src/Bundle/DependencyInjection/SimplePayExtension.php`
- Create: `src/Bundle/Resources/config/services.xml`

Nincs unit teszt — a bundle DI regisztrációját az integrált alkalmazás (pl. Membrum) fogja tesztelni. A `services.xml` csak a `SimplePayGatewayFactory`-t regisztrálja `payum.gateway_factory` tag-gel; a konkrét gateway-konfigurációt (merchant_id, secret_key) az alkalmazás YAML-je adja meg.

- [ ] **Step 1: Hozd létre a `src/Bundle/SimplePayBundle.php`-t**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Bundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class SimplePayBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
```

- [ ] **Step 2: Hozd létre a `src/Bundle/DependencyInjection/SimplePayExtension.php`-t**

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Bundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

class SimplePayExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(\dirname(__DIR__) . '/Resources/config'));
        $loader->load('services.xml');
    }
}
```

- [ ] **Step 3: Hozd létre a `src/Bundle/Resources/config/services.xml`-t**

```xml
<?xml version="1.0" encoding="UTF-8" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services
               https://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <service id="codeconjure.simplepay.gateway_factory"
                 class="CodeConjure\SimplePayPayum\SimplePayGatewayFactory"
                 public="false">
            <tag name="payum.gateway_factory"
                 factory_name="simplepay"
                 factory_title="OTP SimplePay"/>
        </service>
    </services>
</container>
```

- [ ] **Step 4: Commit**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
git add src/Bundle/ && \
git commit -m "feat: Symfony bundle with DI registration"
```

---

### Task 10: README

**Files:**
- Create: `README.md`

- [ ] **Step 1: Hozd létre a `README.md`-t**

````markdown
# codeconjure/simplepay-payum

OTP SimplePay v2 Payum gateway with Symfony bundle support.

## Features

- Payment initiation with GET redirect to SimplePay
- Return URL handling with HMAC signature verification
- IPN (Instant Payment Notification) handler
- Refund support
- Symfony bundle with PayumBundle auto-configuration

## Installation

```bash
composer require codeconjure/simplepay-payum
```

A PSR-18 HTTP client is required at runtime. Install one of:

```bash
composer require symfony/http-client nyholm/psr7
# or
composer require guzzlehttp/guzzle php-http/guzzle7-adapter
```

## Configuration (Symfony + PayumBundle)

```yaml
# config/packages/payum.yaml
payum:
  gateways:
    simplepay:
      factory: simplepay
      merchant_id: '%env(SIMPLEPAY_MERCHANT_ID)%'
      secret_key: '%env(SIMPLEPAY_SECRET_KEY)%'
      sandbox: '%env(bool:SIMPLEPAY_SANDBOX)%'
```

```dotenv
SIMPLEPAY_MERCHANT_ID=MERCHANTID001
SIMPLEPAY_SECRET_KEY=FxDa5w314kLlNseq2sKuVwaqZshZT5d6
SIMPLEPAY_SANDBOX=true
```

## Payment details

Set the return and IPN URLs in the payment details before capture:

```php
$payment->setDetails([
    'url' => [
        'success' => $captureUrl . '?simplepay_result=SUCCESS',
        'fail'    => $captureUrl . '?simplepay_result=FAIL',
        'cancel'  => $captureUrl . '?simplepay_result=CANCEL',
        'timeout' => $captureUrl . '?simplepay_result=TIMEOUT',
        'dn'      => $notifyUrl,
    ],
]);
```

Where `$captureUrl` is the Payum capture token URL and `$notifyUrl` is the Payum notify token URL.

## Status mapping

| SimplePay status | Payum status |
|---|---|
| `COMPLETE` | Captured |
| `WAITING`, `IN_PAYMENT` | Pending |
| `CANCELLED` | Canceled |
| `TIMEOUT` | Expired |
| `REFUND`, `REFUND_PARTIAL` | Refunded |
| `FRAUD`, `CARD_DECLINED`, `ERROR` | Failed |

## License

MIT
````

- [ ] **Step 2: Commit**

```bash
cd /Users/connor/Work/opensource/simplepay-payum && \
git add README.md && \
git commit -m "docs: README with installation and configuration guide"
```
