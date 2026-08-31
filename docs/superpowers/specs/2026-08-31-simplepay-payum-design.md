# `codeconjure/simplepay-payum` — tervdokumentum

| | |
|---|---|
| Dátum | 2026-08-31 |
| Állapot | jóváhagyva, implementációra vár |
| Fázis | 2. fázis, első csomag |
| Repo | https://github.com/connorhu/simplepay-payum |
| Előzmény | `codeconjure/simplepay` (1. fázis), `docs/superpowers/specs/2026-08-30-simplepay-protocol-package-design.md` |

---

## 1. Cél

Payum gateway a SimplePay v2 fizetéshez, a `codeconjure/simplepay` protokoll-kliens fölött.
A csomag a Payum absztrakcióját beszéli: actionök, gateway factory, `Reply`-ok, `details` tömb.
**Syliusról semmit nem tud**, és nem is szabad tudnia.

Ez a három rétegre bontott integráció középső rétege:

```
codeconjure/simplepay                 protokoll: aláírás, endpointok, DTO-k, hibakódok
codeconjure/simplepay-payum           ← ez a csomag
codeconjure/simplepay-sylius-plugin   Sylius admin, rendelés-leképezés, controllerek
```

## 2. A rétegzés szabálya

A megrendelő kikötése, szó szerint:

> „A lib megmarad egy általános lib. Ha bármi olyan kell, ami Sylius-specifikus, akkor az a SimplePay pluginba kerüljön. Ami Payum-specifikus, az a simplepay-payum csomagba."

Ebből következik a csomag két határa:

- **Lefelé:** a protokoll-csomaghoz nem nyúlunk. Ha kiderül, hogy egy valóban általános
  képesség hiányzik belőle, az külön döntés, külön PR, külön indoklás.
- **Felfelé:** a csomag nem ismer rendelést, vevőt, számlázási címet, állapotgépet.
  Egy **kész**, gateway-független `details` tömböt fogyaszt, amit a fölötte lévő réteg állít elő.

### 2.1 Miért nincs itt `ConvertPaymentAction`

A Payum `Convert` action feladata a keretrendszer fizetés-objektumát API-payloaddá alakítani.
Ehhez rendelésszám, számlázási cím, vevő e-mail és a keretrendszer pénz-ábrázolása kell —
ez mind Sylius-tudás. **A Convert ezért a Sylius pluginba kerül**, nem ide.

A régi implementációban ez a határ elmosódott: a `TransactionPayloadFactory` egyszerre ismerte
a Payum `Capture`-t, a Sylius `Order`-t és a SimplePay mezőneveit. Ez a spec ezt szétvágja.

## 3. Csomag-azonosítók

| | |
|---|---|
| Név | `codeconjure/simplepay-payum` |
| Namespace | `CodeConjure\SimplePayPayum\` |
| Licenc | MIT |
| PHP | `^8.4` |

### 3.1 Függőségek

```json
"require": {
    "php": "^8.4",
    "codeconjure/simplepay": "^1.0",
    "payum/core": "^1.7 || ^2.0",
    "psr/log": "^3.0"
},
"require-dev": {
    "nyholm/psr7": "^1.8",
    "php-http/mock-client": "^1.6",
    "phpstan/phpstan": "^2.0",
    "phpunit/phpunit": "^12.0",
    "sylius-labs/coding-standard": "^4.4",
    "symfony/config": "^7.0",
    "symfony/dependency-injection": "^7.0",
    "symfony/http-foundation": "^7.0",
    "symfony/http-kernel": "^7.0"
}
```

**`payum/core: ^1.7 || ^2.0`** — a célalkalmazás (Sylius 2.1) `payum/core` **1.7.7**-et használ,
nem 2.0-t. A `codeconjure/barion-payum` `^2.0` kikötése ezért nem másolható át. A használt
felület — `Reply\HttpResponse`, `Request\GetHttpRequest`, `GatewayFactory`, `ApiAwareTrait`,
`Bridge\Spl\ArrayObject` — mindkét ágon azonos.

A Symfony csomagok **csak dev-függőségek**: a Bundle opcionális felület. A csomag magja
keretrendszer nélkül is használható.

## 4. Fájlstruktúra

```
src/
    Api.php                              merchant, environment, currency, a protokoll-kliens
    SimplePayGatewayFactory.php
    Details.php                          tipizált details olvasó/író, konstans kulcsokkal
    StatusMap.php                        TransactionStatus → Payum status, kimerítő match
    TransitionGuard.php                  megengedett átmenetek + duplikátum-észlelés
    IpnLog.php                           korlátos audit-napló a details-ben
    Action/
        CaptureAction.php
        NotifyAction.php
        ResolveIpnAction.php
        StatusAction.php
        SyncAction.php
        RefundAction.php
    Request/
        GetHttpRequestWithHeaders.php
        ResolveSimplePayIpn.php
    Exception/
        SimplePayPayumException.php      interfész
        MissingDetailsException.php
        UnexpectedIpnException.php
    Bundle/
        SimplePayPayumBundle.php
        DependencyInjection/SimplePayPayumExtension.php
        Builder/SimplePayGatewayFactoryBuilder.php
        Resources/config/services.xml
tests/
    Unit/…                               tükrözi az src/ szerkezetét
    Fixtures/ipn_finished.json           a hivatalos dokumentáció IPN-mintája
```

## 5. A `details` szerződés

A két csomag közötti interfész maga a Payum `details` tömb, **két elkülönített névtérrel**:

| kulcs | ki írja | ki olvassa | tartalom |
|---|---|---|---|
| `simplepay_request` | felső réteg (Convert) | ez a csomag (Capture) | `orderRef`, `total`, `currency`, `customerEmail`, `invoice{}`, `urls{}`, `language`, `methods[]` |
| `simplepay_state` | ez a csomag | mindkettő | `transactionId`, `status`, `paymentUrl`, `timeout`, `attempt`, `lastCheckedAt`, `ipnLog[]` |
| `simplepay_refund` | felső réteg (parancs) | ez a csomag (Refund) | `amount` — a kért jóváírás alegységben |

A `Details` osztály tipizált olvasó/írót ad konstans kulcsokkal. **Nyers string-indexelés
tilos mindkét csomagban.** Ez zárja ki szerkezetileg a régi implementáció 2-es hibáját
(`order_ref` olvasása ott, ahol `orderRef` volt): egy elgépelt kulcs statikus hiba lesz,
nem néma `null`.

A `simplepay_request` értékei skalárok és tömbök, nem DTO-k — a `details` JSON-ként
perzisztálódik. A DTO-vá alakítás a `CaptureAction`-ben történik, hangos hibával, ha
kötelező mező hiányzik.

## 6. `Api`

```php
final readonly class Api
{
    public function __construct(
        public Client $client,
        public string $merchant,
        public Environment $environment,
        public Currency $currency,
    ) {}
}
```

A `currency` azért kerül ide, mert a SimplePay merchant azonosító **pénznemhez kötött**:
egy merchant egy pénznemet fogad. Ez nem Sylius-tudás, hanem a szolgáltatás tulajdonsága,
tehát a Payum-rétegben a helye.

A `SimplePayGatewayFactory` a Payum config alapján állítja elő, a beadott PSR-18 kliens és
PSR-17 factory-k felhasználásával. Hiányzó vagy üres `merchant`/`secretKey` esetén a
protokoll-csomag `ConfigurationException`-t dob — nem építünk fölé saját, elnézőbb validációt.

## 7. Actionök

### 7.1 `CaptureAction`

1. `simplepay_request` kiolvasása. Hiányzik → `MissingDetailsException`
   (azt jelenti, hogy a Convert nem futott le — programozói hiba, nem futásidejű állapot).
2. **Újra-capture őr.** Ha van `simplepay_state.transactionId`, a státusza nem végleges,
   és a `timeout` még nem járt le → `HttpRedirect` a **meglévő** `paymentUrl`-re.
   Nem indítunk új tranzakciót.
3. Egyébként `StartRequest` összeállítása és `Client::start()`.
4. `simplepay_state` írása: `transactionId`, `paymentUrl`, `timeout`,
   `status = TransactionStatus::Init`, és a `simplepay_request.attempt` **átvétele**
   a `simplepay_state.attempt` kulcsba.
5. `throw new HttpRedirect($response->paymentUrl)`.

**Az `attempt` számláló tulajdonosa.** A felső réteg (Convert) minden alkalommal
`simplepay_state.attempt + 1`-et javasol a `simplepay_request`-ben; a `simplepay_state.attempt`
viszont **csak akkor lép**, amikor a 3. pont ténylegesen elindított egy tranzakciót. Ha a 2. pont
újrahasznosít, a javasolt érték eldobódik, és a következő Convert ugyanazt javasolja újra.
Így a számláló pontosan a valóban elindított tranzakciókat számolja, és az `orderRef` egyedisége
sem sérül.

Kivételek: a protokoll-csomag kivételei **változatlanul továbbdobódnak**. A `RequestException`
hibakódjai a `simplepay_state`-be íródnak a továbbdobás előtt, hogy az admin lássa, miért
bukott. **Automatikus újrapróbálás nincs, semmilyen szinten** — egy vak `/start` ismétlés
dupla terhelést okozhat.

### 7.2 `NotifyAction`

1. `GetHttpRequestWithHeaders` végrehajtása → nyers törzs (`->content`) és fejlécek.
2. `Signature` fejléc kiolvasása. Hiányzik vagy üres → `UnexpectedIpnException`.
   **Nincs alapértelmezés, nincs aláírás nélküli feldolgozás.**
3. `Client::ipn($rawBody, $signatureHeader)` — ez ellenőrzi az aláírást és azt is,
   hogy a törzs `merchant` mezője a konfigurált merchanthoz tartozik.
4. `TransitionGuard` futtatása a `simplepay_state.status` és az IPN státusza között.
5. Eredménytől függően:
   - **alkalmazható** → `status`, `transactionId`, `method`, `paymentDate`, `finishDate` írása
   - **duplikátum** (ugyanaz a státusz) → nem íródik, `ipnLog` bejegyzés `warning` szinten
   - **tiltott átmenet** → nem íródik, `ipnLog` bejegyzés `error` szinten
6. Mindhárom esetben:
   `throw new HttpResponse($confirmation->responseBody(), 200, ['Signature' => $confirmation->responseSignature()])`

**A 6. pont nem opcionális.** A SimplePay addig ismétli az értesítést, amíg meg nem kapja az
aláírt visszaigazolást. Egy olyan üzenetet, amit megkaptunk és hitelesítettünk, akkor is vissza
kell igazolni, ha az állapotgépünk nem tud vele mit kezdeni — különben egy állapotgép-nézeteltérés
végtelen ismétlési ciklussá válik. A régi `SimplePayWebhookProcessor::reject()` kivételt dobott
és semmit nem válaszolt; ez a spec ezt javítja.

Visszaigazolás **nem** megy: érvénytelen aláírásra és idegen merchantra. Mindkettő kivétel,
amit a hívó controller 400-as válasszá alakít.

**A hívó felelőssége a perzisztálás a reply eldobása után.** A `HttpResponse` egy `ReplyInterface`,
amit `throw` visz ki az actionből — a details frissítése tehát egy kivétel-úton hagyja el az
actiont. A hívó rétegnek `finally` ágban (vagy azzal egyenértékűen) kell a modellt visszaírnia,
különben egy sikeresen feldolgozott IPN állapotfrissítése elveszik. A csomag ezt nem tudja
kikényszeríteni, ezért itt van kimondva; a Sylius plugin oldalán ezt a keretrendszer
`ExecuteSameRequestWithPaymentDetailsAction`-je biztosítja.

### 7.3 `ResolveIpnAction` és a `ResolveSimplePayIpn` request

```php
final class ResolveSimplePayIpn
{
    public function __construct(
        public readonly string $rawBody,
        public readonly string $signature,
    ) {}

    public function getMessage(): IpnMessage;   // az action tölti
}
```

Modell nélküli request: ellenőriz és parse-ol, semmit nem ír. A felső réteg ezzel dönti el,
**melyik fizetéshez tartozik** az IPN, mielőtt modellt adna a `Notify`-nak. Enélkül a hívónak
az aláíratlan törzsből kellene `orderRef`-et olvasnia.

Az aláírás így kétszer számolódik (itt és a `NotifyAction`-ben). HMAC-SHA384 egy néhány
száz bájtos törzsön — elhanyagolható ár azért, hogy minden kriptográfia a Payum és a
protokoll rétegben maradjon.

### 7.4 `SyncAction`

`Sync` request → `Client::query()` az `orderRef` vagy `transactionId` alapján →
`TransitionGuard` → `simplepay_state` frissítés, `lastCheckedAt` írása.

**Itt, és csak itt történik hálózati hívás státuszolvasáskor.**

### 7.5 `StatusAction`

`GetStatusInterface` → **tiszta olvasás** a `simplepay_state.status`-ból → `StatusMap`.
Nincs hálózat, nincs mellékhatás. Ha nincs még státusz → `markNew()`.

A régi `StatusAction` minden státuszolvasásnál lekérdezést indított
(`shouldRefreshTransaction()`), ami egy admin listaoldalon fizetésenként egy HTTP kérést
jelentett volna. A Payum konvenció a `Sync`/`GetStatus` szétválasztása; ez a spec ezt követi.

### 7.6 `RefundAction`

A Payum `Refund` requestnek nincs összeg mezője, ezért a hívó a `simplepay_refund.amount`
kulcsba írja a szándékot. Az action onnan olvassa, `Client::refund()`-ot hív, és a választ
a `simplepay_state`-be írja: `refundTransactionId`, `refundTotal`, `remainingTotal`.

Hiányzó `simplepay_refund.amount` → `MissingDetailsException`. **Nincs olyan alapértelmezés,
hogy „akkor a teljes összeg"** — egy jóváírás összegét nem találjuk ki.

## 8. `StatusMap`

```php
match ($status) {
    TransactionStatus::Init,
    TransactionStatus::InPayment,
    TransactionStatus::InFraud      => $request->markPending(),
    TransactionStatus::Authorized   => $request->markAuthorized(),
    TransactionStatus::Finished     => $request->markCaptured(),
    TransactionStatus::Cancelled,
    TransactionStatus::Timeout      => $request->markCanceled(),
    TransactionStatus::NotAuthorized,
    TransactionStatus::Fraud        => $request->markFailed(),
    TransactionStatus::Reversed,
    TransactionStatus::Refund       => $request->markRefunded(),
};
```

Kimerítő mind a 11 enum-esetre, `default` ág **nélkül**. Ha a protokoll-csomag új státuszt
vesz fel, ez a `match` fordítási hibát ad, nem néma `unknown`-t.

> **A régi implementáció tizenegyedik hibája.** A `SimplePayStatusMapper::STATUS_MAP` 29 darab
> kisbetűs kulcsot tartalmazott (`paid`, `captured`, `completed`, …), a SimplePay 11 valódi
> státuszából viszont csak hármat (`authorized`, `cancelled`, `timeout`). **`FINISHED` — a
> sikeres fizetés — nem szerepelt a térképben, tehát `unknown`-ra képződött.** Ugyanígy
> `NOTAUTHORIZED`, `INPAYMENT`, `FRAUD`, `REVERSED`, `INIT`. A `SimplePayWebhookProcessor`
> első ellenőrzése pedig épp az `unknown` státuszt utasította el — a webhook, ha egyáltalán
> eljutott volna odáig, minden sikeres fizetést elutasított volna. Ez a hiba nem szerepelt
> a 2. fázis promptjának tízes listáján; a spec írása közben derült ki.

`Authorized` → `markAuthorized()`: a kétlépéses fizetést ez a csomag nem támogatja, tehát
ez az állapot nem várható. A leképezés mégis őszinte marad, ahelyett hogy `pending`-be
söpörnénk egy olyan állapotot, ami valójában lefoglalt, de le nem hívott összeget jelent.

## 9. `TransitionGuard`

Az átmenetek a `TransactionStatus` enumon vannak megfogalmazva, nem kanonikus vödrökön:

| jelenlegi állapot | mi fogadható el |
|---|---|
| nincs még státusz | bármi |
| `Init`, `InPayment`, `Authorized`, `InFraud` | bármi |
| `Finished` | **csak** `Refund` és `Reversed` |
| `Cancelled`, `Timeout`, `NotAuthorized`, `Fraud`, `Reversed`, `Refund` | semmi |

A régi tábla `STATUS_SUCCESSFUL => []`-t mondott, vagyis egy sikeres fizetés után **semmit**
nem fogadott el — a jóváírás-értesítést sem. A `Finished → Refund` átmenet engedélyezése ezt
javítja.

Az azonos státusz ismétlődése nem átmenet, hanem **duplikátum**: külön eredmény, külön
naplószint, és nem íródik felül tőle a state.

## 10. `IpnLog`

A `simplepay_state.ipnLog` **korlátos** lista, az utolsó 20 bejegyzés. Bejegyzésenként:

```
receivedAt      ISO 8601
transactionId
status          a bejövő státusz
outcome         applied | duplicate | rejected
repeatCount     hányadszor érkezett ugyanerre a transactionId + status párra
```

A régi `simplepay_callback_audit_log` korlátlanul nőtt egy JSON oszlopban.

**A `repeatCount` diagnosztikai műszer.** A SimplePay addig ismétli az IPN-t, amíg el nem
fogadja a visszaigazolást. Ha egy `transactionId + status` pár ismételten megérkezik,
az azt jelenti, hogy a visszaigazolásunkat **nem fogadták el** — és a jelenleg egyetlen
ismert gyanúsított a `receiveDate` időbélyeg formátuma (lásd 13. fejezet). Ezért a duplikátum
`warning` szintű naplóbejegyzés, nem néma eldobás.

## 11. `GetHttpRequestWithHeaders`

```php
final class GetHttpRequestWithHeaders extends GetHttpRequest
{
    /** @var array<string, list<string|null>> */
    public array $headers = [];
}
```

A Payum `GetHttpRequest` osztályon `#[\AllowDynamicProperties]` van, és a Symfony-híd
(`Payum\Bundle\PayumBundle\Action\GetHttpRequestAction::updateRequest()`) dinamikusan tűzi rá
a `headers` értéket — deklarált property nélkül. Ez phpstan level 9 alatt nem definiált
property olvasása.

A `supports()` `instanceof GetHttpRequest`-et néz, tehát az alosztályt ugyanaz az action
szolgálja ki, és ugyanaz az értékadás tölti a `headers`-t — csak most deklarált, tipizált
property-be.

**Ismert korlát:** a `Payum\Core\Bridge\PlainPhp\Action\GetHttpRequestAction` nem tölti a
fejléceket. Ilyen környezetben a `headers` üres marad, és a `NotifyAction` hangosan elbukik
„hiányzó `Signature` fejléc" hibával. A README kimondja: **az IPN feldolgozása Symfony-hidat
igényel.** Ez tudatos, dokumentált korlát, nem csendes degradáció.

## 12. Bundle

A `SimplePayPayumBundle` egyetlen dolgot regisztrál: a gateway factory buildert.

```php
#[AutoconfigureTag(name: 'payum.gateway_factory_builder', attributes: ['factory' => 'simplepay'])]
```

A `sylius.gateway_configuration_type` tag **nem itt van** — az a Sylius plugin dolga.
Ez a két bekötési pont választja szét a két csomagot a Symfony konténerben.

A Bundle nem hoz PSR-18 klienst: az alkalmazás adja be. A `services.xml` a
`Psr\Http\Client\ClientInterface` és `Psr\Http\Message\RequestFactoryInterface` /
`StreamFactoryInterface` szolgáltatásokra hivatkozik.

## 13. Tesztstratégia

### 13.1 A protokoll-kliens nem mockolható

A `CodeConjure\SimplePay\Client` **`final`, és nincs interfésze**. Ez az 1. fázis tudatos
döntése. A csomag tesztjei ezért **valódi `Client`-et** építenek egy `php-http/mock-client`
PSR-18 implementáció fölé — ugyanaz a minta, amit az 1. fázis is használt.

Következmény: a Payum-réteg tesztjei a valódi JSON-szerializáláson, a valódi HMAC-SHA384
aláíráson és a valódi válasz-parse-oláson keresztül futnak. Egy elrontott mezőnév itt
kiderül, nem egy kitalált teszt-dublőr mögé bújik.

**Interfészt nem vezetünk be a protokoll-csomagba pusztán a mockolhatóságért.**

### 13.2 Fixture

`tests/Fixtures/ipn_finished.json` — a hivatalos SimplePay dokumentáció IPN-mintája:

```json
{"salt":"…","orderRef":"101010515680292482600","method":"CARD","merchant":"PUBLICTESTHUF",
 "finishDate":"2019-09-09T14:46:18+0200","paymentDate":"2019-09-09T14:41:13+0200",
 "transactionId":99844942,"status":"FINISHED"}
```

Két megfigyelés, amit tesztnek kell rögzítenie:

- **`transactionId` JSON-szám, nem string.** A protokoll-csomag `PayloadReader::string()`
  bármely skalárt elfogad és castol, tehát átmegy — de ez egy ki nem mondott feltevés volt,
  és pontosan az ilyenekből élt a régi kód típushibája. Teszt rögzíti.
- **Nincs benne `total` és `currency`.** Következmény: **az IPN-ből összeg nem egyeztethető.**
  A régi `SimplePayWebhookProcessor` `total` összevetése elvileg sem működhetett. Az összeg
  megerősítése csak `query()`-ből jöhet, azaz a `SyncAction`-ből.

A fixture aláírása a teszt saját titkával számolódik újra — a dokumentáció mintájához nem
tartozik érvényes aláírás.

### 13.3 Kötelező teszteset-csoportok

| Csoport | Mit állít |
|---|---|
| `CaptureAction` | payload-leképezés, újra-capture őr (élő és lejárt tranzakcióra), `HttpRedirect`, hiányzó `simplepay_request` |
| `NotifyAction` | **mikor megy vissza aláírt visszaigazolás és mikor nem** — alkalmazott, duplikátum, tiltott átmenet, rossz aláírás, idegen merchant, hiányzó `Signature` fejléc |
| `StatusMap` | mind a 11 enum-eset leképezése |
| `TransitionGuard` | a 9. fejezet táblája, külön a `Finished → Refund` esetre |
| `IpnLog` | a 20-as korlát, a `repeatCount` növelése |
| `SyncAction` | `query()` hívás, átmenet-őr alkalmazása |
| `RefundAction` | összeg olvasása a details-ből, hiányzó összeg → kivétel |
| `StatusAction` | **hálózati hívás nem történik** |

## 14. CI

`phpstan level 9` és `ecs` tisztán, PHPUnit a `simplepay-lib` `ci.yaml`-jának mintájára.
Sandbox-csoport ebben a csomagban **nincs** — a hálózati kontraktus a protokoll-csomag
felelőssége.

## 15. Fegyelmi mércék

Az 1. fázisból átvéve:

- Minden osztály `final`, az adathordozók `readonly`.
- Szigorú összehasonlítás mindenhol; a `"0"` mindig jogos érték, sosem üresség.
- **Nincs néma degradáció:** hiányzó kötelező adatra nincs alapértelmezés, nincs olyan
  kapcsoló, aminek az eredményét eldobjuk, és nincs olyan mező a publikus felületen,
  amit sosem töltünk ki.
- Magyar commit üzenetek, Conventional Commits előtaggal.

## 16. Ismert bizonytalanságok

- **Nem tudjuk, elfogadja-e a SimplePay a `receiveDate` formátumunkat.** A protokoll-csomag
  `DateTimeInterface::ATOM`-ot ad (`+02:00`); a hivatalos dokumentáció 2019-es példája
  kettőspont nélküli offsetet mutat (`+0200`). Az 1. fázis a kettőspontos alak mellett döntött
  (a 2025–26-os dokumentációs példák és a gyártói SDK `date('c')` hívása alapján) —
  ez **következtetés, nem mérés**.
  Ez a csomag nem dönti el a kérdést, de **műszert ad hozzá**: a `IpnLog.repeatCount`
  megmutatja, ha a visszaigazolásainkat elutasítják. Ha kiderül, hogy rossz, a javítás egy
  konstans a protokoll-csomagban, külön PR-ben.
- **A `WIRE` fizetési mód nincs kipróbálva élőben**, és az átutalásos folyamat
  (a beérkezésig nyitva maradó tranzakció) állapotkezeléséről ez a csomag semmit nem modellez.
  A `TransitionGuard` táblája kártyás folyamatra íródott.

## 17. Elfogadási kritériumok

1. Egy Payum `Capture` a `details`-ből valódi `/start` kérést állít elő és átirányít.
2. Egy aláírt IPN a `NotifyAction`-ön keresztül 200-as, aláírt visszaigazolást kap —
   alkalmazott, duplikátum és tiltott átmenet esetén egyaránt.
3. Érvénytelen aláírás és idegen merchant esetén **nem** kap visszaigazolást.
4. `StatusAction` bizonyítottan nem hálózatozik.
5. `phpstan level 9` és `ecs` tisztán.
6. A 13.3 táblázat minden csoportja lefedve.

## 18. Hatókörön kívül

Kétlépéses fizetés (`/finish`), ismétlődő fizetés (`/do`, `/auto`), tárolt kártya, token
kezelés, `Cancel` action (a SimplePay-nek nincs megfelelő endpointja), automatikus
újrapróbálás, és bármilyen Sylius-ismeret.
