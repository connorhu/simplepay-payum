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

### Bundle registration

If your application does not use Symfony Flex, register the bundle manually in `config/bundles.php`:

```php
return [
    // ...
    CodeConjure\SimplePayPayum\Bundle\SimplePayBundle::class => ['all' => true],
];
```

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
