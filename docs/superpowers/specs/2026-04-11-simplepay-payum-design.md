# SimplePay Payum Gateway — Design

> **Státusz:** Jóváhagyva.

## Cél

Önálló, nyílt forráskódú Composer csomag (`coreconjure/simplepay-payum`), amely OTP SimplePay v2 REST API integrációt biztosít a Payum fizetési absztrakcióhoz. Tartalmaz Symfony Bundle-t a PayumBundle-lel való egyszerű integrációhoz.

---

## Csomag azonosítók

- **Composer:** `coreconjure/simplepay-payum`
- **PHP namespace:** `Coreconjure\SimplePayPayum`
- **Payum factory name:** `simplepay`
- **Symfony Bundle:** `SimplePayBundle`

---

## Követelmények

- PHP `^8.1`
- `payum/core` `^1.7`
- `php-http/httplug` `^2.0`
- `php-http/message` `^1.0`

Dev:
- `phpunit/phpunit` `^11.0`
- `php-http/mock-client` `^1.0`
- `symfony/framework-bundle` `^7.0`

---

## Fájlstruktúra

```
coreconjure/simplepay-payum
├── src/
│   ├── Action/
│   │   ├── CaptureAction.php
│   │   ├── ConvertPaymentAction.php
│   │   ├── NotifyAction.php
│   │   ├── RefundAction.php
│   │   └── StatusAction.php
│   ├── Api.php
│   ├── SimplePayGatewayFactory.php
│   └── Bundle/
│       ├── SimplePayBundle.php
│       └── DependencyInjection/
│           └── SimplePayExtension.php
├── tests/
│   ├── Action/
│   │   ├── CaptureActionTest.php
│   │   ├── NotifyActionTest.php
│   │   ├── RefundActionTest.php
│   │   └── StatusActionTest.php
│   └── ApiTest.php
├── composer.json
└── README.md
```

---

## Api osztály

**Felelőssége:** HTTP kommunikáció a SimplePay v2 REST API-val és HMAC-SHA256 aláírás kezelés.

**Konstruktor paraméterek:**
- `string $merchantId`
- `string $secretKey`
- `bool $sandbox`
- `HttpClientInterface $httpClient`
- `RequestFactoryInterface $requestFactory`
- `StreamFactoryInterface $streamFactory`

**Végpontok:**
- Sandbox: `https://sandbox.simplepay.hu/payment/v2/`
- Éles: `https://simplepay.otpbank.hu/payment/v2/`

**Publikus metódusok:**
- `startPayment(array $data): array` — POST `/start`
- `queryPayment(array $orderRefs): array` — POST `/query`
- `refundPayment(array $data): array` — POST `/refund`
- `verifySignature(string $body, string $signature): bool` — HMAC ellenőrzés

**HMAC aláírás generálás:**
```php
$signature = base64_encode(hash_hmac('sha256', $jsonBody, $this->secretKey, true));
```
Header: `Signature: <base64_value>`

**Hiba kezelés:** Sikertelen HTTP válasz (non-2xx) esetén `HttpException`-t dob. Érvénytelen aláírás esetén `InvalidSignatureException`-t dob.

---

## Actions

### ConvertPaymentAction

Payum `PaymentInterface`-ből SimplePay details array-t épít.

**Mezők:**
- `orderRef` ← `$payment->getNumber()`
- `currency` ← `$payment->getCurrencyCode()`
- `total` ← `$payment->getTotalAmount()` (már fillérben, int)
- `customerEmail` ← `$payment->getClientEmail()`
- `language` ← `'HU'` (alapértelmezett)
- `timeout` ← aktuális idő + 30 perc, ISO 8601 formátum
- `url.success/fail/cancel/timeout/dn` ← a Payum capture URL-ből deriválva (query paraméterként megkülönböztetve)

### CaptureAction

Két ág:

**1. Fizetés indítása** (ha a model nem tartalmaz `transactionId`-t):
- Meghívja `Api::startPayment()` a model adataival
- Elmenti a kapott `transactionId`-t és `paymentUrl`-t a modellbe
- `HttpPostRedirect` reply-jal átirányít a SimplePay fizetési oldalra

**2. Visszatérés kezelése** (ha `transactionId` már megvan):
- `GetHttpRequest` segítségével kiolvasssa az `r` és `s` query paramétereket
- Meghívja `Api::verifySignature($r, $s)` — érvénytelen aláírás esetén `status = FRAUD`
- Meghívja `Api::queryPayment()` a `transactionId` alapján
- Frissíti a model `status` mezőjét a SimplePay válasz alapján

### NotifyAction

Szerver-szerver IPN POST kezelés:

- `GetHttpRequest` segítségével olvassa ki a raw body-t és a `Signature` headert
- Meghívja `Api::verifySignature()` — érvénytelen aláírás esetén `400 Bad Request`
- Meghívja `Api::queryPayment()` a body-ból kiolvasott `transactionId` alapján
- Frissíti a model státuszát
- `200 OK` választ küld vissza (`HttpResponse` reply)

### StatusAction

SimplePay → Payum státusz mapping:

| SimplePay | Payum |
|---|---|
| (üres) | `markNew()` |
| `WAITING`, `IN_PAYMENT` | `markPending()` |
| `COMPLETE` | `markCaptured()` |
| `CANCELLED` | `markCanceled()` |
| `TIMEOUT` | `markExpired()` |
| `REFUND`, `REFUND_PARTIAL` | `markRefunded()` |
| `FRAUD`, `CARD_DECLINED`, `ERROR` | `markFailed()` |
| egyéb | `markUnknown()` |

### RefundAction

- Meghívja `Api::refundPayment()` a model `transactionId`-jével és az összeg mezővel
- Frissíti a model `status` mezőjét `REFUND`-ra sikeres válasz esetén
- Hiba esetén `LogicException`-t dob

---

## SimplePayGatewayFactory

Extends `\Payum\Core\GatewayFactory`. A `populateConfig()` metódusban:

- `payum.factory_name` → `'simplepay'`
- `payum.factory_title` → `'OTP SimplePay'`
- `payum.action.capture` → `new CaptureAction()`
- `payum.action.notify` → `new NotifyAction()`
- `payum.action.refund` → `new RefundAction()`
- `payum.action.status` → `new StatusAction()`
- `payum.action.convert_payment` → `new ConvertPaymentAction()`
- `payum.api` → `Api` példány a config `merchant_id`, `secret_key`, `sandbox` értékeiből

---

## Symfony Bundle

**`SimplePayBundle`** regisztrálja a `SimplePayExtension`-t.

**`SimplePayExtension`** betölti a `Resources/config/services.xml`-t:
- `Api` service (paraméterek: merchant_id, secret_key, sandbox + http client)
- `SimplePayGatewayFactory` service (tagged: `payum.gateway_factory`)

**Konfiguráció** (`config/packages/simplepay.yaml`):

```yaml
payum:
  gateways:
    simplepay:
      factory: simplepay
      merchant_id: '%env(SIMPLEPAY_MERCHANT_ID)%'
      secret_key: '%env(SIMPLEPAY_SECRET_KEY)%'
      sandbox: '%env(bool:SIMPLEPAY_SANDBOX)%'
```

---

## Tesztek

**`ApiTest`:**
- HMAC generálás helyes formátumot ad
- `startPayment` valid JSON body-t és `Signature` headert küld
- Non-2xx válasz esetén `HttpException` dobódik
- `verifySignature` érvényes aláírást elfogad, érvénytelent elutasít

**`StatusActionTest`:**
- Minden SimplePay státusz a megfelelő Payum státuszra mappolódik
- Üres státusz → `markNew()`
- Ismeretlen státusz → `markUnknown()`

**`CaptureActionTest`:**
- `transactionId` nélkül: `HttpPostRedirect` reply visszaadja a `paymentUrl`-t
- `transactionId` meglétekor: `queryPayment` meghívódik, model státusza frissül
- Érvénytelen visszatérési aláírás: státusz `FRAUD` lesz

**`NotifyActionTest`:**
- Valid HMAC: `queryPayment` meghívódik, `HttpResponse` reply `200 OK`
- Invalid HMAC: `HttpResponse` reply `400 Bad Request`

**`RefundActionTest`:**
- Sikeres refund: `refundPayment` meghívódik, model státusza `REFUND`
- Hibás válasz: `LogicException` dobódik

---

## Nincs ebben a csomagban

- Symfony Controller a return URL kezeléshez — ez az integrált alkalmazás felelőssége (pl. Membrum)
- Adatbázis / Payum storage — Payum core felelőssége
- Barion integráció — külön csomag lesz
