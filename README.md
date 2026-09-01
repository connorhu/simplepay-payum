# codeconjure/simplepay-payum

Payum gateway az OTP SimplePay v2 fizetéshez, a
[`codeconjure/simplepay`](https://github.com/connorhu/simplepay-lib)
protokoll-kliens fölött. **Sylius-független.**

Ez a három rétegre bontott SimplePay integráció középső rétege:

- `codeconjure/simplepay` — aláírás, endpointok, DTO-k, hibakódok, IPN
- **`codeconjure/simplepay-payum`** — ez a csomag
- `codeconjure/simplepay-sylius-plugin` — Sylius admin és rendelés-leképezés

## Telepítés

```bash
composer require codeconjure/simplepay-payum
```

A csomag nem hoz HTTP-implementációt. Kell mellé egy PSR-18 kliens és egy
PSR-17 factory:

```bash
composer require symfony/http-client nyholm/psr7
```

Symfony alatt vedd fel a bundle-t a `config/bundles.php`-ba:

```php
CodeConjure\SimplePayPayum\Bundle\SimplePayPayumBundle::class => ['all' => true],
```

## Konfiguráció

Négy kulcs:

| kulcs | érték |
|---|---|
| `merchant` | a SimplePay kereskedői azonosító |
| `secretKey` | a hozzá tartozó titkos kulcs |
| `environment` | `sandbox` vagy `production` |
| `currency` | `HUF`, `EUR` vagy `USD` |

**A pénznem merchant-hez kötött:** egy SimplePay merchant azonosító egy
pénznemet fogad. Több pénznemhez több merchant kell, tehát több gateway.

## A `details` szerződés

Ez a csomag egy **kész** payloadot fogyaszt: nem tudja, mi az a rendelés,
vevő vagy számlázási cím. A fölötte lévő réteg (Sylius plugin vagy saját
`Convert` action) tölti ki a `simplepay_request` névteret:

```php
$details['simplepay_request'] = [
    'orderRef' => 'RENDELES-42-1',
    'total' => 1000,               // a pénznem VALÓDI alegysége: HUF-nál forint
    'currency' => 'HUF',
    'customerEmail' => 'vevo@example.com',
    'invoice' => ['name' => '…', 'country' => 'HU', 'city' => '…', 'zip' => '…', 'address' => '…'],
    'urls' => ['success' => '…', 'fail' => '…', 'cancel' => '…', 'timeout' => '…'],
    'language' => 'HU',
    'methods' => ['CARD'],
    'attempt' => 1,
];
```

A csomag a `simplepay_state` névtérbe ír: `transactionId`, `status`,
`paymentUrl`, `timeout`, `attempt`, `lastCheckedAt`, `method`, `paymentDate`,
`finishDate`, `refundTransactionId`, `refundTotal`, `remainingTotal`,
`ipnLog`, `lastErrorCodes`. Az `attempt` a `Capture` legutóbbi lefutásából
származik, a `lastErrorCodes` egy sikertelen `/start` hibakódjait őrzi meg.

Jóváíráshoz a hívó a `simplepay_refund.amount` kulcsba írja az összeget.

## Actionök

| Payum request | Mit csinál |
|---|---|
| `Capture` | `/start`, majd `HttpRedirect` a fizetőoldalra |
| `Notify` | IPN feldolgozás, `HttpResponse` reply aláírt visszaigazolással |
| `ResolveSimplePayIpn` | modell nélküli ellenőrzés és parse — melyik fizetéshez tartozik |
| `Sync` | `/query`, az egyetlen hálózati hívás státuszolvasáskor |
| `GetStatus` | tiszta olvasás a tárolt állapotból, hálózat nélkül |
| `Refund` | `/refund` a details-be írt összeggel |

**A `Capture` `PaymentAlreadySettledException`-t dob, ha a tárolt státusz
már `FINISHED`, `REFUND` vagy `REVERSED`** — ekkor nem küld HTTP kérést,
és nem indít új tranzakciót. Az újrapróbálkozás csak egy sikertelen
kísérlet (`CANCELLED`, `TIMEOUT`, `NOTAUTHORIZED`, `FRAUD`) után jogos.

## Az IPN feldolgozása

A SimplePay egyetlen, a kereskedői vezérlőpanelen beállított URL-re posztol —
**per-kérésben nincs IPN-cím mező.** A címet a „Technikai adatok" menüpont
alatt kell megadni, fiókonként külön.

A hívó controller feladata:

```php
$gateway->execute($ipn = new ResolveSimplePayIpn($rawBody, $signatureHeader));
$payment = $this->findPaymentBy($ipn->getMessage()->orderRef);   // most már hitelesített adat

try {
    $gateway->execute(new Notify($payment));
} catch (HttpResponse $reply) {
    $response = new Response($reply->getContent(), $reply->getStatusCode(), $reply->getHeaders());
} finally {
    // a modellt a reply eldobása UTÁN is perzisztálni kell, egyébként egy
    // sikeresen feldolgozott IPN nyomtalan marad
    $this->paymentRepository->add($payment);
}

return $response;
```

**A modell visszaírása a hívó felelőssége.** A `HttpResponse` egy
`ReplyInterface`, amit `throw` visz ki az actionből — a details frissítése
tehát kivétel-úton hagyja el. `finally` ágban (vagy azzal egyenértékűen)
kell perzisztálni, különben egy sikeresen feldolgozott IPN nyomtalan marad.

**A `$reply` elkapása önmagában nem elég — vissza is kell adni.** Ha a
controller csak elkapja a `HttpResponse`-t és nem küldi tovább a
kliensnek (HTTP válaszként), a SimplePay sosem kapja meg az aláírt
visszaigazolást, és a végtelenségig ismételni fogja ugyanazt az
értesítést — pontosan az a hiba, amit ez a csomag a tervezésével meg
akar előzni.

### Az `ipnLog` mint műszer

A `simplepay_state.ipnLog` az utolsó 20 értesítést tartja meg,
`repeatCount` számlálóval. A SimplePay addig ismétli az értesítést, amíg
meg nem kapja az aláírt visszaigazolást — **ha ez a szám nő, a
visszaigazolásunkat nem fogadták el.** A jelenleg egyetlen ismert
gyanúsított a `receiveDate` időbélyeg formátuma (lásd lent).

### Symfony-hidat igényel

A `NotifyAction` a Payum `GetHttpRequest`-en keresztül jut a nyers törzshöz
és a `Signature` fejléchez. A `Payum\Core\Bridge\PlainPhp` hídja **nem tölti
a fejléceket**, ezért ott a feldolgozás hangosan elbukik hiányzó
`Signature` fejléc hibával.

## Ismert bizonytalanságok

- **Nem tudjuk, elfogadja-e a SimplePay a `receiveDate` formátumunkat.** A
  protokoll-csomag `DateTimeInterface::ATOM`-ot ad (`+02:00`); a hivatalos
  dokumentáció 2019-es példája kettőspont nélküli offsetet mutat (`+0200`).
  A döntés a kettőspontos alak mellett a 2025–26-os dokumentációs példákon és
  a gyártói SDK `date('c')` hívásán alapul — **következtetés, nem mérés.**
  Ez a csomag nem dönti el a kérdést, de műszert ad hozzá (`ipnLog.repeatCount`).
- **A `WIRE` fizetési mód nincs kipróbálva élőben.** Az átutalásos folyamat
  a beérkezésig nyitva marad; erről az átmenet-őr táblája semmit nem modellez,
  kártyás folyamatra íródott.

## Ismert korlátok

- **A részleges jóváírás Payum felől TELJES jóváírásnak látszik.** A
  `StatusMap` a `REFUND` státuszt `markRefunded()`-ra képezi, a
  `remainingTotal`-ra tekintet nélkül — Payum nem tud arról, hogy a
  vásárlónak esetleg még jár vissza pénz. Ráadásul, ha a tárolt státusz
  egyszer `REFUND` lett, az átmenet-őr onnantól egyetlen további `Sync`-kel
  sem engedi megváltoztatni a státuszt (lásd a `TransitionGuard`
  táblázatát): egy ismételt, azonos `REFUND` `Duplicate`-nek, minden más
  `Rejected`-nek minősül — a `status` mező utólag egyik esetben sem
  javítható. **A részleges jóváírás emiatt nincs teljesen modellezve ebben
  a csomagban.**
- **A `RefundAction` a `refundTotal`-t és a `remainingTotal`-t minden
  hívásnál felülírja, nem összegzi.** Több, egymást követő részleges
  jóváírás esetén a `simplepay_state` mindig csak a LEGUTOLSÓ jóváírás
  adatait tartalmazza — a korábbi részjóváírások összege a tárolt
  állapotból nem rekonstruálható.

## Tesztelés

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

A tesztek **valódi** protokoll-klienst építenek egy mock PSR-18 kliens fölé:
a `CodeConjure\SimplePay\Client` `final` és nincs interfésze, tehát nem
mockolható. Cserébe minden teszt a valódi JSON-szerializáláson, a valódi
HMAC-SHA384 aláíráson és a valódi válasz-parse-oláson keresztül fut.

## Licenc

MIT
