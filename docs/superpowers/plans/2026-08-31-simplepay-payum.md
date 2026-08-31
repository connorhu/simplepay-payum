# `codeconjure/simplepay-payum` implementációs terv

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Payum gateway a SimplePay v2 fizetéshez, a `codeconjure/simplepay` protokoll-kliens fölött, Sylius-ismeret nélkül.

**Architecture:** A csomag a Payum `details` tömbjét használja szerződésként. A felső réteg a `simplepay_request` kulcsba írja a fizetés adatait; ez a csomag onnan olvas, a `simplepay_state` kulcsba ír, és Payum actionökön keresztül dolgozik. Minden protokoll-hívás a beadott `Client`-en megy, minden státusz-döntés a `TransactionStatus` enumon.

**Tech Stack:** PHP 8.4, `codeconjure/simplepay ^1.0`, `payum/core ^1.7 || ^2.0`, `psr/log ^3.0`. Teszt: PHPUnit 12, `php-http/mock-client`, PHPStan level 9, ECS (sylius-labs).

**Spec:** `docs/superpowers/specs/2026-08-31-simplepay-payum-design.md`

## Global Constraints

- PHP `^8.4`. Minden fájl `declare(strict_types=1);`-gyel kezdődik.
- Namespace: `CodeConjure\SimplePayPayum\`, tesztek: `CodeConjure\SimplePayPayum\Tests\`.
- **Minden osztály `final`**, az adathordozók `readonly`.
- **Szigorú összehasonlítás mindenhol** (`===`, `!==`). A `"0"` és a `0` mindig jogos érték, sosem üresség.
- **Nincs néma degradáció:** hiányzó kötelező adatra nincs alapértelmezés, nincs olyan kapcsoló, aminek az eredményét eldobjuk, és nincs olyan mező a publikus felületen, amit sosem töltünk ki.
- **A protokoll-csomaghoz (`codeconjure/simplepay`) nem nyúlunk.** Ha hiányzik belőle valami, az külön PR és külön indoklás.
- **A GPL-es hivatalos SDK-ból kódot másolni tilos.** Tényeket (mezőnevek, formátumok) át lehet venni.
- Automatikus újrapróbálás sehol nincs.
- Magyar commit üzenetek, Conventional Commits előtaggal.
- Minden task végén zöld: `vendor/bin/phpunit`, `vendor/bin/phpstan analyse -c phpstan.dist.neon`, `vendor/bin/ecs check`.

## Eltérés a spectől, tudatosan

A spec 4. fejezete három kivételt sorolt fel (`SimplePayPayumException`, `MissingDetailsException`, `UnexpectedIpnException`). Az implementáció **ötöt** használ, mert az `UnexpectedIpnException` két, egymástól független hibát fedett volna le. A végleges készlet a 2. taskban van definiálva. A spec ettől nem sérül: a hierarchia továbbra is aszerint tagolódik, mit kell tenni a hibával.

## Fájlszerkezet

| Fájl | Felelősség |
|---|---|
| `src/Exception/*.php` | kivétel-hierarchia, egy kivétel egy ok |
| `src/Internal/ArrayReader.php` | tipizált olvasás `array<string,mixed>`-ből, hangos hibával |
| `src/Model/StartData.php` | amit a felső réteg ad: a `/start` kéréshez szükséges adatok |
| `src/Model/TransactionState.php` | amit megtudtunk: tranzakció-azonosító, státusz, IPN-napló |
| `src/Model/IpnLogEntry.php`, `IpnOutcome.php` | egy IPN-feldolgozás nyoma |
| `src/Details.php` | a Payum `ArrayObject` és a két modell közötti híd |
| `src/Api.php` | a Payum `payum.api`: kliens + merchant + környezet + pénznem |
| `src/StatusMap.php` | `TransactionStatus` → Payum status, kimerítő `match` |
| `src/TransitionGuard.php` | melyik státuszváltás fogadható el |
| `src/Request/*.php` | két saját Payum request |
| `src/Action/*.php` | hat action |
| `src/SimplePayGatewayFactory.php` | a Payum gateway összeszerelése |
| `src/Bundle/**` | opcionális Symfony felület |

---

### Task 1: Csomag-alap és a régi prototípus eltakarítása

**Files:**
- Delete: `src/` és `tests/` teljes tartalma (az áprilisi prototípus), `phpunit.xml`
- Modify: `composer.json`
- Create: `phpunit.xml.dist`, `phpstan.dist.neon`, `ecs.php`, `.gitignore`, `.github/workflows/ci.yaml`

**Interfaces:**
- Consumes: semmit
- Produces: futtatható `vendor/bin/phpunit`, `vendor/bin/phpstan`, `vendor/bin/ecs`

- [ ] **Step 1: A régi prototípus törlése**

Az áprilisi kód teljes egészében kiváltásra kerül; egyetlen fájlja sem marad meg.

```bash
cd /server/www/egyhazzene.hu/incubator/simplepay-payum
git rm -r --quiet src tests phpunit.xml
```

- [ ] **Step 2: `composer.json` felülírása**

```json
{
    "name": "codeconjure/simplepay-payum",
    "description": "Payum gateway for OTP SimplePay v2 — Sylius independent",
    "type": "library",
    "license": "MIT",
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
    },
    "suggest": {
        "symfony/http-client": "PSR-18 client implementation",
        "nyholm/psr7": "Lightweight PSR-7 and PSR-17 implementation"
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
    "repositories": [
        {
            "type": "path",
            "url": "../simplepay",
            "options": { "symlink": true }
        }
    ],
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "php-http/discovery": false,
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    },
    "minimum-stability": "stable"
}
```

A `repositories` blokk a fejlesztés idejére köti be a szomszédos protokoll-csomagot. A `codeconjure/simplepay` még nincs a Packagiston; a `^1.0` kikötés a path repository `1.0.x-dev` verziójára fog illeszkedni, ha a protokoll-csomag `composer.json`-jában van `version` mező. **Ha a `composer update` „could not be found" hibát ad**, add hozzá a protokoll-csomag `composer.json`-jához a `"version": "1.0.0"` sort, és futtasd újra — ez a path repository szokásos követelménye.

- [ ] **Step 3: Eszköz-konfigurációk**

`phpstan.dist.neon`:

```neon
parameters:
    level: 9
    paths:
        - src
        - tests
```

`ecs.php`:

```php
<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function (ECSConfig $config): void {
    $config->import('vendor/sylius-labs/coding-standard/ecs.php');
    $config->paths([__DIR__ . '/src', __DIR__ . '/tests']);
};
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnWarning="true"
         failOnRisky="true"
         failOnNotice="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

`.gitignore`:

```
/vendor/
/composer.lock
/.phpunit.cache/
```

- [ ] **Step 4: CI**

`.github/workflows/ci.yaml`:

```yaml
name: CI

on:
    push: ~
    pull_request: ~
    workflow_dispatch: ~

permissions:
    contents: read

jobs:
    checks:
        name: "Tesztek és statikus ellenőrzés (PHP ${{ matrix.php }})"
        runs-on: ubuntu-latest
        strategy:
            fail-fast: false
            matrix:
                php: ['8.4', '8.5']
        steps:
            - uses: actions/checkout@v4

            - uses: shivammathur/setup-php@v2
              with:
                  php-version: ${{ matrix.php }}
                  coverage: none

            - uses: ramsey/composer-install@v3

            - name: Unit tesztek
              run: vendor/bin/phpunit

            - name: PHPStan
              run: vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress

            - name: ECS
              run: vendor/bin/ecs check --no-progress-bar
```

> **Megjegyzés a CI-hoz:** a `repositories` path bejegyzés a GitHub runneren nem fog működni, mert ott nincs szomszédos `../simplepay` könyvtár. Amíg a protokoll-csomag nincs a Packagiston, a CI job `composer install` lépése bukni fog. **Ez a Task 15 feladata**, ott van rá a megoldás; addig a CI pirosan állhat, és a helyi futtatás a mérce.

- [ ] **Step 5: Ellenőrzés**

```bash
composer update
mkdir -p src tests/Unit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: mindkettő hiba nélkül fut le (üres kódbázison is). A `phpunit` üres suite-ra „No tests executed" figyelmeztetést ad — ez rendben van, még nincs teszt.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: csomag-alap, eszközök és a régi prototípus eltakarítása

Az áprilisi prototípus teljes egészében törölve; a 2026-08-31-es terv váltja ki.
PHP 8.4 minimum, phpstan level 9, ECS, PHPUnit 12."
```

---

### Task 2: Kivétel-hierarchia

**Files:**
- Create: `src/Exception/SimplePayPayumException.php`, `MissingDetailsException.php`, `InvalidGatewayConfigException.php`, `MissingSignatureHeaderException.php`, `TransactionNotFoundException.php`
- Test: `tests/Unit/Exception/ExceptionHierarchyTest.php`

**Interfaces:**
- Consumes: semmit
- Produces: `SimplePayPayumException` interfész; öt konkrét kivétel, mind implementálja

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Exception/ExceptionHierarchyTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Exception;

use CodeConjure\SimplePayPayum\Exception\InvalidGatewayConfigException;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Exception\MissingSignatureHeaderException;
use CodeConjure\SimplePayPayum\Exception\SimplePayPayumException;
use CodeConjure\SimplePayPayum\Exception\TransactionNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    /** @return iterable<string, array{class-string<\Throwable>, class-string<\Throwable>}> */
    public static function exceptions(): iterable
    {
        yield 'hiányzó details' => [MissingDetailsException::class, \LogicException::class];
        yield 'hibás gateway config' => [InvalidGatewayConfigException::class, \LogicException::class];
        yield 'hiányzó aláírás-fejléc' => [MissingSignatureHeaderException::class, \RuntimeException::class];
        yield 'nem talált tranzakció' => [TransactionNotFoundException::class, \RuntimeException::class];
    }

    /**
     * @param class-string<\Throwable> $exceptionClass
     * @param class-string<\Throwable> $parentClass
     */
    #[DataProvider('exceptions')]
    public function testEveryExceptionIsCatchableThroughTheInterfaceAndItsParent(
        string $exceptionClass,
        string $parentClass,
    ): void {
        $exception = new $exceptionClass('teszt');

        self::assertInstanceOf(SimplePayPayumException::class, $exception);
        self::assertInstanceOf($parentClass, $exception);
    }

    public function testTheInterfaceExtendsThrowableSoASingleCatchWorks(): void
    {
        self::assertTrue(is_a(SimplePayPayumException::class, \Throwable::class, true));
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Exception/ExceptionHierarchyTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Exception\SimplePayPayumException" not found`.

- [ ] **Step 3: A kivételek megírása**

`src/Exception/SimplePayPayumException.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * Minden, a csomag által dobott kivétel közös interfésze — egyetlen `catch`
 * mindet elkapja.
 *
 * A hierarchia nem aszerint tagolódik, hol keletkezett a hiba, hanem aszerint,
 * hogy a hívónak mit kell tennie vele: a `LogicException` leszármazottak
 * programozói hibát jeleznek (kódot vagy konfigurációt kell javítani), a
 * `RuntimeException` leszármazottak futásidejű, adatból eredő helyzetet.
 */
interface SimplePayPayumException extends \Throwable
{
}
```

`src/Exception/MissingDetailsException.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * A Payum `details` tömbből hiányzik egy kötelező adat.
 *
 * Ez mindig programozói hiba: azt jelenti, hogy a felső réteg (a `Convert`
 * action vagy a hívó parancs) nem futott le, vagy nem azt írta be, amit
 * ez a csomag vár. Nincs olyan futásidejű állapot, amiben egy `Capture`
 * jogosan találna üres `simplepay_request` kulcsot.
 */
final class MissingDetailsException extends \LogicException implements SimplePayPayumException
{
}
```

`src/Exception/InvalidGatewayConfigException.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * A Payum gateway konfigurációja hiányos vagy értelmezhetetlen — például
 * ismeretlen `environment` vagy `currency` érték.
 */
final class InvalidGatewayConfigException extends \LogicException implements SimplePayPayumException
{
}
```

`src/Exception/MissingSignatureHeaderException.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * Az IPN HTTP kérésből hiányzik vagy üres a `Signature` fejléc.
 *
 * Külön kivétel, nem általános „váratlan IPN": aláírás nélkül a törzs
 * hitelesíthetetlen, tehát nincs mit feldolgozni. Alapértelmezés nincs,
 * és aláírás nélküli feldolgozás sincs.
 */
final class MissingSignatureHeaderException extends \RuntimeException implements SimplePayPayumException
{
}
```

`src/Exception/TransactionNotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * A `/query` lekérdezés nem adott vissza tranzakciót arra az azonosítóra,
 * amit a `simplepay_state` tárol.
 *
 * Nem néma `null`: ha egy elindított tranzakciót a SimplePay nem ismer,
 * az valódi ellentmondás a két rendszer állapota között, és látszania kell.
 */
final class TransactionNotFoundException extends \RuntimeException implements SimplePayPayumException
{
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Exception/ExceptionHierarchyTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Exception tests/Unit/Exception
git commit -m "feat(exception): kivétel-hierarchia a hívói teendő szerint tagolva

Öt kivétel, mind a SimplePayPayumException interfészen keresztül elkapható.
A LogicException leszármazottak programozói hibát jeleznek, a
RuntimeException leszármazottak futásidejű, adatból eredő helyzetet."
```

---

### Task 3: `Internal\ArrayReader` — tipizált olvasás a details tömbből

**Files:**
- Create: `src/Internal/ArrayReader.php`
- Test: `tests/Unit/Internal/ArrayReaderTest.php`

**Interfaces:**
- Consumes: `MissingDetailsException` (Task 2)
- Produces:
  - `ArrayReader::string(array $data, string $key, string $context): string`
  - `ArrayReader::nullableString(array $data, string $key): ?string`
  - `ArrayReader::int(array $data, string $key, string $context): int`
  - `ArrayReader::nullableInt(array $data, string $key): ?int`
  - `ArrayReader::map(array $data, string $key, string $context): array<string, mixed>`
  - `ArrayReader::nullableMap(array $data, string $key): ?array<string, mixed>`
  - `ArrayReader::stringList(array $data, string $key, string $context): list<string>`
  - `ArrayReader::mapList(array $data, string $key): list<array<string, mixed>>`
  - `ArrayReader::nullableDateTime(array $data, string $key): ?\DateTimeImmutable`

A `$context` paraméter a hibaüzenetbe kerül (pl. `'simplepay_request'`), hogy az olvasó tudja, melyik névtérből hiányzik a mező.

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Internal/ArrayReaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Internal;

use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Internal\ArrayReader;
use PHPUnit\Framework\TestCase;

final class ArrayReaderTest extends TestCase
{
    public function testStringReadsAString(): void
    {
        self::assertSame('ABC', ArrayReader::string(['orderRef' => 'ABC'], 'orderRef', 'simplepay_request'));
    }

    public function testStringAcceptsTheLiteralZeroBecauseItIsAValidValue(): void
    {
        self::assertSame('0', ArrayReader::string(['orderRef' => '0'], 'orderRef', 'simplepay_request'));
    }

    public function testStringRejectsAnEmptyString(): void
    {
        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('"orderRef"');

        ArrayReader::string(['orderRef' => ''], 'orderRef', 'simplepay_request');
    }

    public function testStringRejectsAnIntegerRatherThanCastingIt(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::string(['orderRef' => 42], 'orderRef', 'simplepay_request');
    }

    public function testStringNamesTheContextInTheMessage(): void
    {
        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('simplepay_request');

        ArrayReader::string([], 'orderRef', 'simplepay_request');
    }

    public function testIntReadsAnIntegerAndAcceptsZero(): void
    {
        self::assertSame(0, ArrayReader::int(['total' => 0], 'total', 'simplepay_request'));
    }

    public function testIntRejectsANumericString(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::int(['total' => '1000'], 'total', 'simplepay_request');
    }

    public function testNullableStringReturnsNullForMissingAndForEmpty(): void
    {
        self::assertNull(ArrayReader::nullableString([], 'transactionId'));
        self::assertNull(ArrayReader::nullableString(['transactionId' => ''], 'transactionId'));
        self::assertSame('T1', ArrayReader::nullableString(['transactionId' => 'T1'], 'transactionId'));
    }

    public function testNullableIntKeepsZeroAndDropsMissing(): void
    {
        self::assertSame(0, ArrayReader::nullableInt(['attempt' => 0], 'attempt'));
        self::assertNull(ArrayReader::nullableInt([], 'attempt'));
    }

    public function testMapReadsAStringKeyedArray(): void
    {
        self::assertSame(
            ['name' => 'Teszt Elek'],
            ArrayReader::map(['invoice' => ['name' => 'Teszt Elek']], 'invoice', 'simplepay_request'),
        );
    }

    public function testMapRejectsAList(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::map(['invoice' => ['Teszt Elek']], 'invoice', 'simplepay_request');
    }

    public function testStringListReadsAListOfStrings(): void
    {
        self::assertSame(
            ['CARD'],
            ArrayReader::stringList(['methods' => ['CARD']], 'methods', 'simplepay_request'),
        );
    }

    public function testStringListRejectsAnEmptyList(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::stringList(['methods' => []], 'methods', 'simplepay_request');
    }

    public function testMapListReturnsAnEmptyListWhenTheKeyIsAbsent(): void
    {
        self::assertSame([], ArrayReader::mapList([], 'ipnLog'));
    }

    public function testNullableDateTimeParsesAnAtomTimestamp(): void
    {
        $parsed = ArrayReader::nullableDateTime(['timeout' => '2026-08-31T10:00:00+02:00'], 'timeout');

        self::assertInstanceOf(\DateTimeImmutable::class, $parsed);
        self::assertSame('2026-08-31T10:00:00+02:00', $parsed->format(\DateTimeInterface::ATOM));
    }

    public function testNullableDateTimeRejectsAnUnparseableValueRatherThanReturningNull(): void
    {
        $this->expectException(MissingDetailsException::class);

        ArrayReader::nullableDateTime(['timeout' => 'tegnap délután'], 'timeout');
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Internal/ArrayReaderTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Internal\ArrayReader" not found`.

- [ ] **Step 3: Az `ArrayReader` megírása**

`src/Internal/ArrayReader.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Internal;

use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;

/**
 * Tipizált mezőolvasás a Payum `details` tömbjéből.
 *
 * Szándékosan NEM castol: egy `42` ott, ahol stringet várunk, hiba, nem
 * `"42"`. A protokoll-csomag `PayloadReader`-e castol, mert ott a SimplePay
 * válaszát olvassuk, amiben a JSON-típusok a szolgáltató döntései. Itt a
 * saját `details` tömbünket olvassuk, amit mi magunk írtunk — egy típuseltérés
 * itt a mi hibánk, és látszania kell.
 *
 * @internal
 */
final class ArrayReader
{
    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key, string $context): string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || '' === $value) {
            throw self::missing($key, $context, $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key, string $context): int
    {
        $value = $data[$key] ?? null;

        if (!is_int($value)) {
            throw self::missing($key, $context, $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function map(array $data, string $key, string $context): array
    {
        $value = self::nullableMap($data, $key);

        if (null === $value) {
            throw self::missing($key, $context, $data[$key] ?? null);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function nullableMap(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value) || [] === $value) {
            return null;
        }

        foreach (array_keys($value) as $mapKey) {
            if (!is_string($mapKey)) {
                return null;
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return non-empty-list<string>
     */
    public static function stringList(array $data, string $key, string $context): array
    {
        $value = $data[$key] ?? null;

        if (!is_array($value) || [] === $value || !array_is_list($value)) {
            throw self::missing($key, $context, $value);
        }

        $list = [];

        foreach ($value as $item) {
            if (!is_string($item) || '' === $item) {
                throw self::missing($key, $context, $item);
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * A hiányzó kulcs itt üres listát jelent, nem hibát: egy még sosem
     * értesített fizetésnek jogosan nincs IPN-naplója.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function mapList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            /** @var array<string, mixed> $item */
            $list[] = $item;
        }

        return $list;
    }

    /** @param array<string, mixed> $data */
    public static function nullableDateTime(array $data, string $key): ?\DateTimeImmutable
    {
        $raw = self::nullableString($data, $key);

        if (null === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception $exception) {
            throw new MissingDetailsException(
                sprintf('A "%s" mező nem értelmezhető időbélyeg: "%s".', $key, $raw),
                previous: $exception,
            );
        }
    }

    private static function missing(string $key, string $context, mixed $value): MissingDetailsException
    {
        return new MissingDetailsException(sprintf(
            'A(z) "%s" details-névtérből hiányzik vagy rossz típusú a "%s" mező (kapott: %s).',
            $context,
            $key,
            get_debug_type($value),
        ));
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Internal/ArrayReaderTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Internal tests/Unit/Internal
git commit -m "feat(internal): tipizált details-olvasó, castolás nélkül

A protokoll-csomag PayloadReader-ével ellentétben ez nem castol: a saját
details tömbünkben egy típuseltérés a mi hibánk, nem a szolgáltatóé.
A \"0\" és a 0 mindenhol jogos érték."
```

---

### Task 4: `Model\StartData` — amit a felső réteg ad

**Files:**
- Create: `src/Model/StartData.php`
- Test: `tests/Unit/Model/StartDataTest.php`

**Interfaces:**
- Consumes: `ArrayReader` (Task 3), `MissingDetailsException` (Task 2)
- Produces:
  - `new StartData(string $orderRef, int $total, Currency $currency, string $customerEmail, Invoice $invoice, Urls $urls, Language $language, list<PaymentMethod> $methods, int $attempt)`
  - `StartData::fromArray(array<string, mixed> $data): self`
  - `StartData::toArray(): array<string, mixed>`
  - `StartData::toStartRequest(): StartRequest`

A `$total` a **pénznem valódi alegysége** (HUF-nál egész forint) — az átváltás a felső réteg dolga, itt már kész számot kapunk.

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Model/StartDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Model;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Language;
use CodeConjure\SimplePay\PaymentMethod;
use CodeConjure\SimplePay\Request\Invoice;
use CodeConjure\SimplePay\Request\Urls;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Model\StartData;
use PHPUnit\Framework\TestCase;

final class StartDataTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function validArray(): array
    {
        return [
            'orderRef' => 'EZ-2026-0042-17-1',
            'total' => 1000,
            'currency' => 'HUF',
            'customerEmail' => 'vevo@example.com',
            'invoice' => [
                'name' => 'Teszt Elek',
                'country' => 'HU',
                'city' => 'Budapest',
                'zip' => '1011',
                'address' => 'Fő utca 1.',
            ],
            'urls' => [
                'success' => 'https://bolt.hu/vissza?e=success',
                'fail' => 'https://bolt.hu/vissza?e=fail',
                'cancel' => 'https://bolt.hu/vissza?e=cancel',
                'timeout' => 'https://bolt.hu/vissza?e=timeout',
            ],
            'language' => 'HU',
            'methods' => ['CARD'],
            'attempt' => 1,
        ];
    }

    public function testItReadsEveryFieldFromTheDetailsArray(): void
    {
        $data = StartData::fromArray(self::validArray());

        self::assertSame('EZ-2026-0042-17-1', $data->orderRef);
        self::assertSame(1000, $data->total);
        self::assertSame(Currency::HUF, $data->currency);
        self::assertSame('vevo@example.com', $data->customerEmail);
        self::assertSame('Teszt Elek', $data->invoice->name);
        self::assertSame('HU', $data->invoice->country);
        self::assertSame('https://bolt.hu/vissza?e=cancel', $data->urls->cancel);
        self::assertSame(Language::Hu, $data->language);
        self::assertSame([PaymentMethod::Card], $data->methods);
        self::assertSame(1, $data->attempt);
    }

    public function testItRoundTripsThroughTheArrayForm(): void
    {
        $original = self::validArray();

        self::assertSame($original, StartData::fromArray($original)->toArray());
    }

    public function testItKeepsTheOptionalInvoiceFieldsWhenPresent(): void
    {
        $input = self::validArray();
        $input['invoice']['phone'] = '+36301234567';

        $data = StartData::fromArray($input);

        self::assertSame('+36301234567', $data->invoice->phone);
        self::assertSame('+36301234567', $data->toArray()['invoice']['phone']);
    }

    public function testItBuildsAStartRequestWithTheAmountInTheCurrencyMinorUnit(): void
    {
        $request = StartData::fromArray(self::validArray())->toStartRequest();

        $payload = $request->toPayload();

        self::assertSame('EZ-2026-0042-17-1', $payload['orderRef']);
        self::assertSame('1000', $payload['total']);
        self::assertSame('HUF', $payload['currency']);
        self::assertSame(['CARD'], $payload['methods']);
        self::assertArrayHasKey('urls', $payload);
        self::assertArrayNotHasKey('url', $payload);
    }

    public function testItRejectsAnUnknownCurrency(): void
    {
        $input = self::validArray();
        $input['currency'] = 'GBP';

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('GBP');

        StartData::fromArray($input);
    }

    public function testItRejectsAnUnknownLanguage(): void
    {
        $input = self::validArray();
        $input['language'] = 'FR';

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('FR');

        StartData::fromArray($input);
    }

    public function testItRejectsAMissingCustomerEmailRatherThanSendingAnEmptyOne(): void
    {
        $input = self::validArray();
        unset($input['customerEmail']);

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('customerEmail');

        StartData::fromArray($input);
    }

    public function testItRejectsAnEmptyMethodList(): void
    {
        $input = self::validArray();
        $input['methods'] = [];

        $this->expectException(MissingDetailsException::class);

        StartData::fromArray($input);
    }

    public function testItAcceptsAZeroTotalBecauseZeroIsAValidNumber(): void
    {
        $input = self::validArray();
        $input['total'] = 0;

        self::assertSame(0, StartData::fromArray($input)->total);
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Model/StartDataTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Model\StartData" not found`.

- [ ] **Step 3: A `StartData` megírása**

`src/Model/StartData.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Model;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Language;
use CodeConjure\SimplePay\Money;
use CodeConjure\SimplePay\PaymentMethod;
use CodeConjure\SimplePay\Request\Invoice;
use CodeConjure\SimplePay\Request\StartRequest;
use CodeConjure\SimplePay\Request\Urls;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Internal\ArrayReader;

/**
 * A `simplepay_request` details-névtér tipizált alakja: minden, ami egy
 * `/start` kéréshez kell.
 *
 * A `$total` a PÉNZNEM VALÓDI ALEGYSÉGE — HUF-nál egész forint, EUR-nál cent.
 * Az átváltás a felső réteg dolga; ez az osztály kész számot kap, és nem
 * oszt, nem szoroz, nem kerekít.
 */
final readonly class StartData
{
    private const string CONTEXT = 'simplepay_request';

    /** @param non-empty-list<PaymentMethod> $methods */
    public function __construct(
        public string $orderRef,
        public int $total,
        public Currency $currency,
        public string $customerEmail,
        public Invoice $invoice,
        public Urls $urls,
        public Language $language,
        public array $methods,
        public int $attempt,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $invoice = ArrayReader::map($data, 'invoice', self::CONTEXT);
        $urls = ArrayReader::map($data, 'urls', self::CONTEXT);

        return new self(
            orderRef: ArrayReader::string($data, 'orderRef', self::CONTEXT),
            total: ArrayReader::int($data, 'total', self::CONTEXT),
            currency: self::enum(Currency::class, ArrayReader::string($data, 'currency', self::CONTEXT), 'currency'),
            customerEmail: ArrayReader::string($data, 'customerEmail', self::CONTEXT),
            invoice: new Invoice(
                name: ArrayReader::string($invoice, 'name', self::CONTEXT . '.invoice'),
                country: ArrayReader::string($invoice, 'country', self::CONTEXT . '.invoice'),
                city: ArrayReader::string($invoice, 'city', self::CONTEXT . '.invoice'),
                zip: ArrayReader::string($invoice, 'zip', self::CONTEXT . '.invoice'),
                address: ArrayReader::string($invoice, 'address', self::CONTEXT . '.invoice'),
                address2: ArrayReader::nullableString($invoice, 'address2'),
                state: ArrayReader::nullableString($invoice, 'state'),
                phone: ArrayReader::nullableString($invoice, 'phone'),
            ),
            urls: new Urls(
                success: ArrayReader::string($urls, 'success', self::CONTEXT . '.urls'),
                fail: ArrayReader::string($urls, 'fail', self::CONTEXT . '.urls'),
                cancel: ArrayReader::string($urls, 'cancel', self::CONTEXT . '.urls'),
                timeout: ArrayReader::string($urls, 'timeout', self::CONTEXT . '.urls'),
            ),
            language: self::enum(Language::class, ArrayReader::string($data, 'language', self::CONTEXT), 'language'),
            methods: self::methods(ArrayReader::stringList($data, 'methods', self::CONTEXT)),
            attempt: ArrayReader::int($data, 'attempt', self::CONTEXT),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'orderRef' => $this->orderRef,
            'total' => $this->total,
            'currency' => $this->currency->value,
            'customerEmail' => $this->customerEmail,
            'invoice' => $this->invoice->toPayload(),
            'urls' => $this->urls->toPayload(),
            'language' => $this->language->value,
            'methods' => array_map(
                static fn (PaymentMethod $method): string => $method->value,
                $this->methods,
            ),
            'attempt' => $this->attempt,
        ];
    }

    public function toStartRequest(): StartRequest
    {
        return new StartRequest(
            orderRef: $this->orderRef,
            total: Money::fromMinorUnits($this->total, $this->currency),
            customerEmail: $this->customerEmail,
            invoice: $this->invoice,
            urls: $this->urls,
            language: $this->language,
            methods: $this->methods,
        );
    }

    /**
     * @param non-empty-list<string> $values
     *
     * @return non-empty-list<PaymentMethod>
     */
    private static function methods(array $values): array
    {
        return array_map(
            static fn (string $value): PaymentMethod => self::enum(PaymentMethod::class, $value, 'methods'),
            $values,
        );
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private static function enum(string $enum, string $value, string $key): \BackedEnum
    {
        return $enum::tryFrom($value) ?? throw new MissingDetailsException(sprintf(
            'A(z) "%s" details-névtér "%s" mezőjének "%s" értéke ismeretlen. Az ismertek: %s.',
            self::CONTEXT,
            $key,
            $value,
            implode(', ', array_column($enum::cases(), 'value')),
        ));
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Model/StartDataTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS. Ha a `toArray()` körbejárás-teszt bukik az `invoice` opcionális mezői miatt, az azért van, mert az `Invoice::toPayload()` kiszűri az üres értékeket — a teszt `validArray()`-e szándékosan csak a kötelező mezőket adja meg, tehát a körbejárásnak pontosan egyeznie kell.

- [ ] **Step 5: Commit**

```bash
git add src/Model tests/Unit/Model
git commit -m "feat(model): StartData — a felső rétegtől kapott fizetési adat

A total a pénznem valódi alegysége; ez az osztály nem vált át, nem kerekít.
Ismeretlen pénznem, nyelv vagy fizetési mód hangos hiba, nem néma kihagyás."
```

---

### Task 5: `Model\IpnLogEntry` és `Model\IpnOutcome`

**Files:**
- Create: `src/Model/IpnOutcome.php`, `src/Model/IpnLogEntry.php`
- Test: `tests/Unit/Model/IpnLogEntryTest.php`

**Interfaces:**
- Consumes: `ArrayReader` (Task 3), `MissingDetailsException` (Task 2)
- Produces:
  - `enum IpnOutcome: string { Applied = 'applied'; Duplicate = 'duplicate'; Rejected = 'rejected' }`
  - `new IpnLogEntry(\DateTimeImmutable $receivedAt, string $transactionId, TransactionStatus $status, IpnOutcome $outcome, int $repeatCount)`
  - `IpnLogEntry::fromArray(array<string, mixed> $data): self`
  - `IpnLogEntry::toArray(): array<string, mixed>`
  - `IpnLogEntry::matches(string $transactionId, TransactionStatus $status): bool`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Model/IpnLogEntryTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Model;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Model\IpnLogEntry;
use CodeConjure\SimplePayPayum\Model\IpnOutcome;
use PHPUnit\Framework\TestCase;

final class IpnLogEntryTest extends TestCase
{
    private static function entry(): IpnLogEntry
    {
        return new IpnLogEntry(
            receivedAt: new \DateTimeImmutable('2026-08-31T12:00:00+02:00'),
            transactionId: '99844942',
            status: TransactionStatus::Finished,
            outcome: IpnOutcome::Applied,
            repeatCount: 1,
        );
    }

    public function testItSerialisesToAnArrayWithAnAtomTimestamp(): void
    {
        self::assertSame([
            'receivedAt' => '2026-08-31T12:00:00+02:00',
            'transactionId' => '99844942',
            'status' => 'FINISHED',
            'outcome' => 'applied',
            'repeatCount' => 1,
        ], self::entry()->toArray());
    }

    public function testItRoundTripsThroughTheArrayForm(): void
    {
        $array = self::entry()->toArray();

        self::assertSame($array, IpnLogEntry::fromArray($array)->toArray());
    }

    public function testItMatchesOnTheTransactionIdAndStatusPairOnly(): void
    {
        $entry = self::entry();

        self::assertTrue($entry->matches('99844942', TransactionStatus::Finished));
        self::assertFalse($entry->matches('99844942', TransactionStatus::Cancelled));
        self::assertFalse($entry->matches('11111111', TransactionStatus::Finished));
    }

    public function testItRejectsAnUnknownOutcome(): void
    {
        $array = self::entry()->toArray();
        $array['outcome'] = 'talán';

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage('talán');

        IpnLogEntry::fromArray($array);
    }

    public function testItRejectsAnUnknownStatus(): void
    {
        $array = self::entry()->toArray();
        $array['status'] = 'ELKÉPZELT';

        $this->expectException(MissingDetailsException::class);

        IpnLogEntry::fromArray($array);
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Model/IpnLogEntryTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Model\IpnLogEntry" not found`.

- [ ] **Step 3: Az `IpnOutcome` és az `IpnLogEntry` megírása**

`src/Model/IpnOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Model;

/**
 * Mi történt egy beérkezett IPN-nel.
 *
 * A `Duplicate` és a `Rejected` egyaránt azt jelenti, hogy az állapotot NEM
 * írtuk felül — de nem ugyanaz a helyzet: a duplikátum a SimplePay ismétlése
 * (várható), az elutasítás az állapotgépeink nézeteltérése (nem várható).
 * Mindkettőre megy aláírt visszaigazolás; csak a naplószint különbözik.
 */
enum IpnOutcome: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case Rejected = 'rejected';
}
```

`src/Model/IpnLogEntry.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Model;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Internal\ArrayReader;

/**
 * Egy IPN-feldolgozás nyoma.
 *
 * A `repeatCount` nem statisztika, hanem diagnosztikai műszer: a SimplePay
 * addig ismétli az értesítést, amíg el nem fogadja az aláírt visszaigazolást.
 * Ha ez a szám nő, az azt jelenti, hogy a visszaigazolásunkat NEM fogadták el.
 */
final readonly class IpnLogEntry
{
    private const string CONTEXT = 'simplepay_state.ipnLog';

    public function __construct(
        public \DateTimeImmutable $receivedAt,
        public string $transactionId,
        public TransactionStatus $status,
        public IpnOutcome $outcome,
        public int $repeatCount,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $receivedAt = ArrayReader::nullableDateTime($data, 'receivedAt');

        if (null === $receivedAt) {
            throw new MissingDetailsException(
                'Az IPN-napló bejegyzéséből hiányzik a "receivedAt" időbélyeg.',
            );
        }

        $status = ArrayReader::string($data, 'status', self::CONTEXT);
        $outcome = ArrayReader::string($data, 'outcome', self::CONTEXT);

        return new self(
            receivedAt: $receivedAt,
            transactionId: ArrayReader::string($data, 'transactionId', self::CONTEXT),
            status: TransactionStatus::tryFrom($status) ?? throw self::unknown('status', $status),
            outcome: IpnOutcome::tryFrom($outcome) ?? throw self::unknown('outcome', $outcome),
            repeatCount: ArrayReader::int($data, 'repeatCount', self::CONTEXT),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'receivedAt' => $this->receivedAt->format(\DateTimeInterface::ATOM),
            'transactionId' => $this->transactionId,
            'status' => $this->status->value,
            'outcome' => $this->outcome->value,
            'repeatCount' => $this->repeatCount,
        ];
    }

    public function matches(string $transactionId, TransactionStatus $status): bool
    {
        return $this->transactionId === $transactionId && $this->status === $status;
    }

    private static function unknown(string $key, string $value): MissingDetailsException
    {
        return new MissingDetailsException(sprintf(
            'Az IPN-napló bejegyzésének "%s" mezője ismeretlen értéket tartalmaz: "%s".',
            $key,
            $value,
        ));
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Model/IpnLogEntryTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Model/IpnOutcome.php src/Model/IpnLogEntry.php tests/Unit/Model/IpnLogEntryTest.php
git commit -m "feat(model): IPN-napló bejegyzés ismétlés-számlálóval

A repeatCount diagnosztikai műszer: ha nő, a SimplePay nem fogadta el az
aláírt visszaigazolásunkat. A duplikátum és az elutasítás külön kimenetel,
mert az egyik várható, a másik nem."
```

---

### Task 6: `Model\TransactionState` — amit megtudtunk

**Files:**
- Create: `src/Model/TransactionState.php`
- Test: `tests/Unit/Model/TransactionStateTest.php`

**Interfaces:**
- Consumes: `ArrayReader` (Task 3), `IpnLogEntry`, `IpnOutcome` (Task 5)
- Produces:
  - `new TransactionState(?string $transactionId = null, ?TransactionStatus $status = null, ?string $paymentUrl = null, ?\DateTimeImmutable $timeout = null, int $attempt = 0, ?\DateTimeImmutable $lastCheckedAt = null, ?PaymentMethod $method = null, ?\DateTimeImmutable $paymentDate = null, ?\DateTimeImmutable $finishDate = null, ?string $refundTransactionId = null, ?int $refundTotal = null, ?int $remainingTotal = null, list<IpnLogEntry> $ipnLog = [], list<int> $lastErrorCodes = [])`
  - `TransactionState::fromArray(array<string, mixed> $data): self`
  - `TransactionState::toArray(): array<string, mixed>`
  - `TransactionState::isLive(\DateTimeImmutable $now): bool`
  - `TransactionState::withIpnEntry(\DateTimeImmutable $receivedAt, string $transactionId, TransactionStatus $status, IpnOutcome $outcome): self`
  - `TransactionState::IPN_LOG_LIMIT` (int, 20)

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Model/TransactionStateTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Model;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Model\IpnOutcome;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use PHPUnit\Framework\TestCase;

final class TransactionStateTest extends TestCase
{
    private const string NOW = '2026-08-31T12:00:00+02:00';

    public function testAnEmptyStateRoundTripsToAnEmptyArray(): void
    {
        self::assertSame([], (new TransactionState())->toArray());
        self::assertNull(TransactionState::fromArray([])->transactionId);
        self::assertSame(0, TransactionState::fromArray([])->attempt);
        self::assertSame([], TransactionState::fromArray([])->ipnLog);
    }

    public function testItRoundTripsAFullyPopulatedState(): void
    {
        $array = [
            'transactionId' => '99844942',
            'status' => 'FINISHED',
            'paymentUrl' => 'https://sb-checkout.simplepay.hu/trx/abc',
            'timeout' => '2026-08-31T12:30:00+02:00',
            'attempt' => 2,
            'lastCheckedAt' => '2026-08-31T12:05:00+02:00',
            'method' => 'CARD',
            'paymentDate' => '2026-08-31T12:01:00+02:00',
            'finishDate' => '2026-08-31T12:02:00+02:00',
            'refundTransactionId' => '99844999',
            'refundTotal' => 500,
            'remainingTotal' => 500,
            'ipnLog' => [[
                'receivedAt' => '2026-08-31T12:03:00+02:00',
                'transactionId' => '99844942',
                'status' => 'FINISHED',
                'outcome' => 'applied',
                'repeatCount' => 1,
            ]],
            'lastErrorCodes' => [5321],
        ];

        self::assertSame($array, TransactionState::fromArray($array)->toArray());
    }

    public function testALiveTransactionIsOneThatHasAUrlANonFinalStatusAndAFutureTimeout(): void
    {
        $state = new TransactionState(
            transactionId: 'T1',
            status: TransactionStatus::Init,
            paymentUrl: 'https://sb-checkout.simplepay.hu/trx/abc',
            timeout: new \DateTimeImmutable('2026-08-31T12:30:00+02:00'),
        );

        self::assertTrue($state->isLive(new \DateTimeImmutable(self::NOW)));
    }

    public function testAnExpiredTransactionIsNotLive(): void
    {
        $state = new TransactionState(
            transactionId: 'T1',
            status: TransactionStatus::Init,
            paymentUrl: 'https://sb-checkout.simplepay.hu/trx/abc',
            timeout: new \DateTimeImmutable('2026-08-31T11:30:00+02:00'),
        );

        self::assertFalse($state->isLive(new \DateTimeImmutable(self::NOW)));
    }

    public function testAFinishedTransactionIsNotLiveEvenBeforeItsTimeout(): void
    {
        $state = new TransactionState(
            transactionId: 'T1',
            status: TransactionStatus::Finished,
            paymentUrl: 'https://sb-checkout.simplepay.hu/trx/abc',
            timeout: new \DateTimeImmutable('2026-08-31T12:30:00+02:00'),
        );

        self::assertFalse($state->isLive(new \DateTimeImmutable(self::NOW)));
    }

    public function testATransactionWithoutATimeoutIsNotLiveBecauseWeCannotKnow(): void
    {
        $state = new TransactionState(
            transactionId: 'T1',
            status: TransactionStatus::Init,
            paymentUrl: 'https://sb-checkout.simplepay.hu/trx/abc',
        );

        self::assertFalse($state->isLive(new \DateTimeImmutable(self::NOW)));
    }

    public function testTheFirstIpnEntryForAPairGetsRepeatCountOne(): void
    {
        $state = (new TransactionState())->withIpnEntry(
            new \DateTimeImmutable(self::NOW),
            '99844942',
            TransactionStatus::Finished,
            IpnOutcome::Applied,
        );

        self::assertCount(1, $state->ipnLog);
        self::assertSame(1, $state->ipnLog[0]->repeatCount);
    }

    public function testARepeatedPairIncrementsTheCounter(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        $state = (new TransactionState())
            ->withIpnEntry($now, '99844942', TransactionStatus::Finished, IpnOutcome::Applied)
            ->withIpnEntry($now, '99844942', TransactionStatus::Finished, IpnOutcome::Duplicate)
            ->withIpnEntry($now, '99844942', TransactionStatus::Finished, IpnOutcome::Duplicate);

        self::assertCount(3, $state->ipnLog);
        self::assertSame(3, $state->ipnLog[2]->repeatCount);
    }

    public function testADifferentStatusStartsItsOwnCounter(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        $state = (new TransactionState())
            ->withIpnEntry($now, '99844942', TransactionStatus::Init, IpnOutcome::Applied)
            ->withIpnEntry($now, '99844942', TransactionStatus::Finished, IpnOutcome::Applied);

        self::assertSame(1, $state->ipnLog[1]->repeatCount);
    }

    public function testTheLogKeepsOnlyTheMostRecentEntries(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $state = new TransactionState();

        for ($i = 0; $i < TransactionState::IPN_LOG_LIMIT + 5; ++$i) {
            $state = $state->withIpnEntry($now, 'T' . $i, TransactionStatus::Init, IpnOutcome::Applied);
        }

        self::assertCount(TransactionState::IPN_LOG_LIMIT, $state->ipnLog);
        self::assertSame('T24', $state->ipnLog[TransactionState::IPN_LOG_LIMIT - 1]->transactionId);
        self::assertSame('T5', $state->ipnLog[0]->transactionId);
    }

    public function testTrimmingTheLogDoesNotResetTheRepeatCounter(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $state = (new TransactionState())
            ->withIpnEntry($now, 'T', TransactionStatus::Finished, IpnOutcome::Applied);

        for ($i = 0; $i < TransactionState::IPN_LOG_LIMIT; ++$i) {
            $state = $state->withIpnEntry($now, 'T', TransactionStatus::Finished, IpnOutcome::Duplicate);
        }

        $last = $state->ipnLog[TransactionState::IPN_LOG_LIMIT - 1];

        self::assertSame(TransactionState::IPN_LOG_LIMIT + 1, $last->repeatCount);
    }
}
```

> A `testTrimmingTheLogDoesNotResetTheRepeatCounter` a legfontosabb teszt ebben a
> taskban. A naiv megvalósítás a megmaradt bejegyzésekből számolná a
> `repeatCount`-ot, és a levágás után újraindulna a számláló — pont akkor
> veszítenénk el a diagnosztikát, amikor a legtöbb ismétlés van. A helyes
> megvalósítás az **utolsó egyező bejegyzés `repeatCount`-jából** lép tovább.

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Model/TransactionStateTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Model\TransactionState" not found`.

- [ ] **Step 3: A `TransactionState` megírása**

`src/Model/TransactionState.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Model;

use CodeConjure\SimplePay\PaymentMethod;
use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Internal\ArrayReader;

/**
 * A `simplepay_state` details-névtér tipizált alakja: minden, amit a
 * SimplePay-től megtudtunk egy fizetésről.
 *
 * Minden mező nullable, mert egy még el nem indított fizetésnek jogosan
 * nincs tranzakció-azonosítója. A `toArray()` a null értékeket kihagyja,
 * hogy a perzisztált details ne teljen meg üres kulcsokkal.
 */
final readonly class TransactionState
{
    public const int IPN_LOG_LIMIT = 20;

    private const string CONTEXT = 'simplepay_state';

    /**
     * @param list<IpnLogEntry> $ipnLog
     * @param list<int>         $lastErrorCodes
     */
    public function __construct(
        public ?string $transactionId = null,
        public ?TransactionStatus $status = null,
        public ?string $paymentUrl = null,
        public ?\DateTimeImmutable $timeout = null,
        public int $attempt = 0,
        public ?\DateTimeImmutable $lastCheckedAt = null,
        public ?PaymentMethod $method = null,
        public ?\DateTimeImmutable $paymentDate = null,
        public ?\DateTimeImmutable $finishDate = null,
        public ?string $refundTransactionId = null,
        public ?int $refundTotal = null,
        public ?int $remainingTotal = null,
        public array $ipnLog = [],
        public array $lastErrorCodes = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = ArrayReader::nullableString($data, 'status');
        $method = ArrayReader::nullableString($data, 'method');

        return new self(
            transactionId: ArrayReader::nullableString($data, 'transactionId'),
            status: null === $status ? null : TransactionStatus::from($status),
            paymentUrl: ArrayReader::nullableString($data, 'paymentUrl'),
            timeout: ArrayReader::nullableDateTime($data, 'timeout'),
            attempt: ArrayReader::nullableInt($data, 'attempt') ?? 0,
            lastCheckedAt: ArrayReader::nullableDateTime($data, 'lastCheckedAt'),
            method: null === $method ? null : PaymentMethod::from($method),
            paymentDate: ArrayReader::nullableDateTime($data, 'paymentDate'),
            finishDate: ArrayReader::nullableDateTime($data, 'finishDate'),
            refundTransactionId: ArrayReader::nullableString($data, 'refundTransactionId'),
            refundTotal: ArrayReader::nullableInt($data, 'refundTotal'),
            remainingTotal: ArrayReader::nullableInt($data, 'remainingTotal'),
            ipnLog: array_map(
                IpnLogEntry::fromArray(...),
                ArrayReader::mapList($data, 'ipnLog'),
            ),
            lastErrorCodes: self::intList($data),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'transactionId' => $this->transactionId,
            'status' => $this->status?->value,
            'paymentUrl' => $this->paymentUrl,
            'timeout' => $this->timeout?->format(\DateTimeInterface::ATOM),
            'attempt' => 0 === $this->attempt ? null : $this->attempt,
            'lastCheckedAt' => $this->lastCheckedAt?->format(\DateTimeInterface::ATOM),
            'method' => $this->method?->value,
            'paymentDate' => $this->paymentDate?->format(\DateTimeInterface::ATOM),
            'finishDate' => $this->finishDate?->format(\DateTimeInterface::ATOM),
            'refundTransactionId' => $this->refundTransactionId,
            'refundTotal' => $this->refundTotal,
            'remainingTotal' => $this->remainingTotal,
            'ipnLog' => [] === $this->ipnLog
                ? null
                : array_map(static fn (IpnLogEntry $entry): array => $entry->toArray(), $this->ipnLog),
            'lastErrorCodes' => [] === $this->lastErrorCodes ? null : $this->lastErrorCodes,
        ];

        return array_filter($data, static fn (mixed $value): bool => null !== $value);
    }

    /**
     * Van élő, még fizethető tranzakciónk?
     *
     * Mindhárom feltétel kell: legyen mire átirányítani, ne legyen még lezárva,
     * és ne járt légyen le. A hiányzó `timeout` NEM jelent örök életet — ha nem
     * tudjuk, meddig érvényes, akkor nem hasznosítjuk újra.
     */
    public function isLive(\DateTimeImmutable $now): bool
    {
        return null !== $this->transactionId
            && null !== $this->paymentUrl
            && null !== $this->status
            && !$this->status->isFinal()
            && null !== $this->timeout
            && $this->timeout > $now;
    }

    public function withIpnEntry(
        \DateTimeImmutable $receivedAt,
        string $transactionId,
        TransactionStatus $status,
        IpnOutcome $outcome,
    ): self {
        $entry = new IpnLogEntry(
            receivedAt: $receivedAt,
            transactionId: $transactionId,
            status: $status,
            outcome: $outcome,
            repeatCount: $this->nextRepeatCount($transactionId, $status),
        );

        $log = $this->ipnLog;
        $log[] = $entry;

        return $this->with(ipnLog: array_values(array_slice($log, -self::IPN_LOG_LIMIT)));
    }

    /**
     * Az utolsó egyező bejegyzés számlálójából lép tovább, nem a megmaradt
     * bejegyzések darabszámából. Enélkül a napló levágása visszaállítaná a
     * számlálót egyre — pont akkor, amikor a legtöbb ismétlés van, tehát
     * amikor a legnagyobb szükség volna a jelzésre.
     */
    private function nextRepeatCount(string $transactionId, TransactionStatus $status): int
    {
        foreach (array_reverse($this->ipnLog) as $entry) {
            if ($entry->matches($transactionId, $status)) {
                return $entry->repeatCount + 1;
            }
        }

        return 1;
    }

    /**
     * @param list<IpnLogEntry>|null $ipnLog
     * @param list<int>|null         $lastErrorCodes
     */
    public function with(
        ?string $transactionId = null,
        ?TransactionStatus $status = null,
        ?string $paymentUrl = null,
        ?\DateTimeImmutable $timeout = null,
        ?int $attempt = null,
        ?\DateTimeImmutable $lastCheckedAt = null,
        ?PaymentMethod $method = null,
        ?\DateTimeImmutable $paymentDate = null,
        ?\DateTimeImmutable $finishDate = null,
        ?string $refundTransactionId = null,
        ?int $refundTotal = null,
        ?int $remainingTotal = null,
        ?array $ipnLog = null,
        ?array $lastErrorCodes = null,
    ): self {
        return new self(
            transactionId: $transactionId ?? $this->transactionId,
            status: $status ?? $this->status,
            paymentUrl: $paymentUrl ?? $this->paymentUrl,
            timeout: $timeout ?? $this->timeout,
            attempt: $attempt ?? $this->attempt,
            lastCheckedAt: $lastCheckedAt ?? $this->lastCheckedAt,
            method: $method ?? $this->method,
            paymentDate: $paymentDate ?? $this->paymentDate,
            finishDate: $finishDate ?? $this->finishDate,
            refundTransactionId: $refundTransactionId ?? $this->refundTransactionId,
            refundTotal: $refundTotal ?? $this->refundTotal,
            remainingTotal: $remainingTotal ?? $this->remainingTotal,
            ipnLog: $ipnLog ?? $this->ipnLog,
            lastErrorCodes: $lastErrorCodes ?? $this->lastErrorCodes,
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<int>
     */
    private static function intList(array $data): array
    {
        $value = $data['lastErrorCodes'] ?? [];

        if (!is_array($value)) {
            return [];
        }

        $codes = [];

        foreach ($value as $code) {
            if (is_int($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
```

> **Figyelem a `with()` metódusra:** `null`-lal nem lehet mezőt *törölni*, csak
> „változatlanul hagyni". Ez itt szándékos és elegendő — a csomagban nincs olyan
> hely, ahol egy már megismert értéket vissza kellene állítani `null`-ra. Ha a
> `CaptureAction` új tranzakciót indít, **nem** a `with()`-et használja, hanem
> egy teljesen új `TransactionState`-et épít (lásd Task 10).

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Model/TransactionStateTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Model/TransactionState.php tests/Unit/Model/TransactionStateTest.php
git commit -m "feat(model): tranzakció-állapot korlátos IPN-naplóval

A napló utolsó 20 bejegyzést tart meg — a régi implementáció korlátlanul
nőtt egy JSON oszlopban. A levágás nem állítja vissza az ismétlés-számlálót:
az utolsó egyező bejegyzésből lép tovább, nem a megmaradtak darabszámából."
```

---

### Task 7: `Details` — híd a Payum `ArrayObject` és a modellek között

**Files:**
- Create: `src/Details.php`
- Test: `tests/Unit/DetailsTest.php`

**Interfaces:**
- Consumes: `StartData` (Task 4), `TransactionState` (Task 6), `ArrayReader` (Task 3), `MissingDetailsException` (Task 2)
- Produces:
  - `Details::REQUEST_KEY = 'simplepay_request'`, `Details::STATE_KEY = 'simplepay_state'`, `Details::REFUND_KEY = 'simplepay_refund'`
  - `Details::fromModel(\ArrayAccess<string, mixed> $model): self`
  - `Details::startData(): StartData` — hiányzik → `MissingDetailsException`
  - `Details::state(): TransactionState` — hiányzik → üres `TransactionState`
  - `Details::writeState(TransactionState $state): void`
  - `Details::writeStartData(StartData $data): void`
  - `Details::refundAmount(): int` — hiányzik → `MissingDetailsException`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/DetailsTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SimplePayPayum\Tests\Unit\Model\StartDataFactory;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;

final class DetailsTest extends TestCase
{
    public function testItReadsTheStartDataFromItsOwnNamespace(): void
    {
        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
        ]);

        self::assertSame('EZ-2026-0042-17-1', Details::fromModel($model)->startData()->orderRef);
    }

    public function testItRefusesToGuessWhenTheStartDataIsAbsent(): void
    {
        $details = Details::fromModel(new ArrayObject([]));

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage(Details::REQUEST_KEY);

        $details->startData();
    }

    public function testAnAbsentStateReadsAsAnEmptyStateNotAnError(): void
    {
        $state = Details::fromModel(new ArrayObject([]))->state();

        self::assertNull($state->transactionId);
        self::assertSame(0, $state->attempt);
    }

    public function testWritingTheStateReplacesTheWholeNamespace(): void
    {
        $model = new ArrayObject([
            Details::STATE_KEY => ['transactionId' => 'RÉGI', 'paymentUrl' => 'https://régi'],
        ]);

        Details::fromModel($model)->writeState(new TransactionState(
            transactionId: 'ÚJ',
            status: TransactionStatus::Init,
        ));

        self::assertSame(
            ['transactionId' => 'ÚJ', 'status' => 'INIT'],
            $model[Details::STATE_KEY],
        );
    }

    public function testWritingTheStateLeavesTheRequestNamespaceUntouched(): void
    {
        $model = new ArrayObject([Details::REQUEST_KEY => StartDataFactory::array()]);

        Details::fromModel($model)->writeState(new TransactionState(transactionId: 'T1'));

        self::assertSame(StartDataFactory::array(), $model[Details::REQUEST_KEY]);
    }

    public function testItReadsTheRefundAmount(): void
    {
        $model = new ArrayObject([Details::REFUND_KEY => ['amount' => 500]]);

        self::assertSame(500, Details::fromModel($model)->refundAmount());
    }

    public function testItRefusesToDefaultTheRefundAmountToTheFullTotal(): void
    {
        $details = Details::fromModel(new ArrayObject([Details::REQUEST_KEY => StartDataFactory::array()]));

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage(Details::REFUND_KEY);

        $details->refundAmount();
    }

    public function testItWritesTheStartDataBack(): void
    {
        $model = new ArrayObject([]);

        Details::fromModel($model)->writeStartData(StartDataFactory::create());

        self::assertSame(StartDataFactory::array(), $model[Details::REQUEST_KEY]);
    }
}
```

Az `StartDataFactory` egy tesztsegéd, hogy a `StartData` érvényes tömbalakja ne
legyen többször lemásolva. `tests/Unit/Model/StartDataFactory.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Model;

use CodeConjure\SimplePayPayum\Model\StartData;

final class StartDataFactory
{
    /** @return array<string, mixed> */
    public static function array(): array
    {
        return [
            'orderRef' => 'EZ-2026-0042-17-1',
            'total' => 1000,
            'currency' => 'HUF',
            'customerEmail' => 'vevo@example.com',
            'invoice' => [
                'name' => 'Teszt Elek',
                'country' => 'HU',
                'city' => 'Budapest',
                'zip' => '1011',
                'address' => 'Fő utca 1.',
            ],
            'urls' => [
                'success' => 'https://bolt.hu/vissza?e=success',
                'fail' => 'https://bolt.hu/vissza?e=fail',
                'cancel' => 'https://bolt.hu/vissza?e=cancel',
                'timeout' => 'https://bolt.hu/vissza?e=timeout',
            ],
            'language' => 'HU',
            'methods' => ['CARD'],
            'attempt' => 1,
        ];
    }

    public static function create(): StartData
    {
        return StartData::fromArray(self::array());
    }
}
```

Ezzel a `StartDataTest` `validArray()` privát metódusa is kiváltható —
cseréld le a `StartDataTest`-ben a `self::validArray()` hívásokat
`StartDataFactory::array()`-re, és töröld a privát metódust.

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/DetailsTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Details" not found`.

- [ ] **Step 3: A `Details` megírása**

`src/Details.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Internal\ArrayReader;
use CodeConjure\SimplePayPayum\Model\StartData;
use CodeConjure\SimplePayPayum\Model\TransactionState;

/**
 * A két csomag közötti szerződés: a Payum `details` tömb két elkülönített
 * névtere.
 *
 * - `simplepay_request` — a felső réteg írja, ez a csomag olvassa
 * - `simplepay_state`   — ez a csomag írja, mindkettő olvassa
 * - `simplepay_refund`  — a felső réteg írja, ez a csomag olvassa
 *
 * Nyers string-indexelés a details tömbön SEHOL nem megengedett — az a
 * régi implementáció hibaosztálya volt (`order_ref` olvasása ott, ahol
 * `orderRef` volt), és néma `null`-ként jelentkezett. Itt minden hozzáférés
 * ezen az osztályon és a `ArrayReader`-en megy át, tehát az elgépelés
 * statikus hiba, nem futásidejű meglepetés.
 */
final class Details
{
    public const string REQUEST_KEY = 'simplepay_request';
    public const string STATE_KEY = 'simplepay_state';
    public const string REFUND_KEY = 'simplepay_refund';

    /** @param \ArrayAccess<string, mixed> $model */
    private function __construct(private readonly \ArrayAccess $model)
    {
    }

    /** @param \ArrayAccess<string, mixed> $model */
    public static function fromModel(\ArrayAccess $model): self
    {
        return new self($model);
    }

    public function startData(): StartData
    {
        return StartData::fromArray($this->readNamespace(self::REQUEST_KEY, required: true));
    }

    public function state(): TransactionState
    {
        return TransactionState::fromArray($this->readNamespace(self::STATE_KEY, required: false));
    }

    public function writeState(TransactionState $state): void
    {
        $this->model[self::STATE_KEY] = $state->toArray();
    }

    public function writeStartData(StartData $data): void
    {
        $this->model[self::REQUEST_KEY] = $data->toArray();
    }

    public function refundAmount(): int
    {
        return ArrayReader::int(
            $this->readNamespace(self::REFUND_KEY, required: true),
            'amount',
            self::REFUND_KEY,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readNamespace(string $key, bool $required): array
    {
        $value = $this->model->offsetExists($key) ? $this->model[$key] : null;

        if ($value instanceof \ArrayObject) {
            $value = $value->getArrayCopy();
        }

        if (!is_array($value)) {
            $value = [];
        }

        $typed = [];

        foreach ($value as $itemKey => $item) {
            if (is_string($itemKey)) {
                $typed[$itemKey] = $item;
            }
        }

        if ($required && [] === $typed) {
            throw new MissingDetailsException(sprintf(
                'A fizetés details tömbjéből hiányzik a "%s" névtér. '
                . 'Ez azt jelenti, hogy a felső réteg (Convert action vagy hívó parancs) nem futott le.',
                $key,
            ));
        }

        return $typed;
    }
}
```

> **Miért `\ArrayAccess` és nem `ArrayObject` a típus:** a Payum
> `Bridge\Spl\ArrayObject` `\ArrayObject`-ből származik, de a Payum requestek
> modellje bármilyen `\ArrayAccess` lehet. A `namespace()` az `\ArrayObject`
> értékeket is kicsomagolja, mert a Payum `ArrayObject::offsetGet()` a beágyazott
> tömböket `ArrayObject`-té alakítja — ez a viselkedés nélkül a `is_array()`
> ellenőrzés csendben üres névteret adna vissza.

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/DetailsTest.php tests/Unit/Model/StartDataTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS mindkét fájlban, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Details.php tests/Unit/DetailsTest.php tests/Unit/Model/StartDataFactory.php tests/Unit/Model/StartDataTest.php
git commit -m "feat: Details — a két réteg közötti szerződés tipizált hozzáféréssel

Három elkülönített névtér a details tömbben. Nyers string-indexelés sehol:
egy elgépelt kulcs statikus hiba, nem néma null. A hiányzó simplepay_request
hangos hiba, a hiányzó simplepay_state üres állapot."
```

---

### Task 8: `Api` — a Payum `payum.api`

**Files:**
- Create: `src/Api.php`
- Test: `tests/Unit/ApiTest.php`

**Interfaces:**
- Consumes: `CodeConjure\SimplePay\Client`, `Environment`, `Currency`
- Produces: `new Api(Client $client, string $merchant, Environment $environment, Currency $currency)` — négy publikus readonly property

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/ApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\Client;
use CodeConjure\SimplePay\Config;
use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    public function testItCarriesTheClientAndTheMerchantLevelFacts(): void
    {
        $factory = new Psr17Factory();
        $client = new Client(
            new Config('PUBLICTESTHUF', 'titok', Environment::Sandbox),
            new MockHttpClient(),
            $factory,
            $factory,
        );

        $api = new Api($client, 'PUBLICTESTHUF', Environment::Sandbox, Currency::HUF);

        self::assertSame($client, $api->client);
        self::assertSame('PUBLICTESTHUF', $api->merchant);
        self::assertSame(Environment::Sandbox, $api->environment);
        self::assertSame(Currency::HUF, $api->currency);
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/ApiTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Api" not found`.

- [ ] **Step 3: Az `Api` megírása**

`src/Api.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePay\Client;
use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;

/**
 * A Payum `payum.api` értéke: a protokoll-kliens és a hozzá tartozó,
 * merchant-szintű tények.
 *
 * A `$currency` azért van itt, mert a SimplePay merchant azonosító
 * PÉNZNEMHEZ KÖTÖTT: egy merchant egy pénznemet fogad, több pénznemhez
 * több merchant kell. Ez nem a keretrendszer tulajdonsága, hanem a
 * szolgáltatásé — tehát a Payum-rétegben a helye, nem a fölötte lévőben.
 *
 * A `$merchant` redundánsnak tűnhet (a `Client` konfigurációjában is benne
 * van), de a `Config` nem publikálja kifelé, és az actionöknek szükségük
 * van rá naplózáshoz és a state feltöltéséhez.
 */
final readonly class Api
{
    public function __construct(
        public Client $client,
        public string $merchant,
        public Environment $environment,
        public Currency $currency,
    ) {
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/ApiTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Api.php tests/Unit/ApiTest.php
git commit -m "feat: Api — protokoll-kliens és merchant-szintű tények

A pénznem itt van, mert a SimplePay merchant azonosító pénznemhez kötött:
egy merchant egy pénznemet fogad. Ez a szolgáltatás tulajdonsága, nem a
keretrendszeré."
```

---

### Task 9: `StatusMap` és `StatusAction` — hálózat nélküli státuszolvasás

**Files:**
- Create: `src/StatusMap.php`, `src/Action/StatusAction.php`
- Test: `tests/Unit/StatusMapTest.php`, `tests/Unit/Action/StatusActionTest.php`

**Interfaces:**
- Consumes: `Details` (Task 7), `TransactionState` (Task 6)
- Produces:
  - `StatusMap::apply(GetStatusInterface $request, ?TransactionStatus $status): void`
  - `new StatusAction()` — paraméter nélküli konstruktor, `ActionInterface`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/StatusMapTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\StatusMap;
use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StatusMapTest extends TestCase
{
    /** @return iterable<string, array{TransactionStatus, string}> */
    public static function statuses(): iterable
    {
        yield 'INIT függőben' => [TransactionStatus::Init, 'isPending'];
        yield 'INPAYMENT függőben' => [TransactionStatus::InPayment, 'isPending'];
        yield 'INFRAUD függőben' => [TransactionStatus::InFraud, 'isPending'];
        yield 'AUTHORIZED felhatalmazva' => [TransactionStatus::Authorized, 'isAuthorized'];
        yield 'FINISHED beszedve' => [TransactionStatus::Finished, 'isCaptured'];
        yield 'CANCELLED megszakítva' => [TransactionStatus::Cancelled, 'isCanceled'];
        yield 'TIMEOUT megszakítva' => [TransactionStatus::Timeout, 'isCanceled'];
        yield 'NOTAUTHORIZED sikertelen' => [TransactionStatus::NotAuthorized, 'isFailed'];
        yield 'FRAUD sikertelen' => [TransactionStatus::Fraud, 'isFailed'];
        yield 'REVERSED jóváírva' => [TransactionStatus::Reversed, 'isRefunded'];
        yield 'REFUND jóváírva' => [TransactionStatus::Refund, 'isRefunded'];
    }

    #[DataProvider('statuses')]
    public function testEveryTransactionStatusMapsToAPayumStatus(
        TransactionStatus $status,
        string $expectedMarker,
    ): void {
        $request = new GetHumanStatus(new \ArrayObject([]));

        StatusMap::apply($request, $status);

        self::assertTrue($request->{$expectedMarker}(), sprintf(
            'A(z) %s státusznak a %s() jelzőt kellene igazra állítania, de a kapott érték %s.',
            $status->value,
            $expectedMarker,
            $request->getValue(),
        ));
    }

    public function testAllElevenStatusesAreCovered(): void
    {
        self::assertCount(
            count(TransactionStatus::cases()),
            iterator_to_array(self::statuses()),
            'A StatusMap tesztje minden TransactionStatus esetet le kell fedjen.',
        );
    }

    public function testAnAbsentStatusIsNewNotUnknown(): void
    {
        $request = new GetHumanStatus(new \ArrayObject([]));

        StatusMap::apply($request, null);

        self::assertTrue($request->isNew());
    }
}
```

> A `testAllElevenStatusesAreCovered` az őrszem: ha a protokoll-csomag új
> enum-esetet vesz fel, ez a teszt azonnal bukik, még mielőtt a `match`
> fordítási hibája elérné a futtatást.

`tests/Unit/Action/StatusActionTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Action;

use CodeConjure\SimplePayPayum\Action\StatusAction;
use CodeConjure\SimplePayPayum\Details;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\TestCase;

final class StatusActionTest extends TestCase
{
    public function testItReadsTheStoredStatusWithoutTouchingTheNetwork(): void
    {
        $model = new ArrayObject([
            Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED'],
        ]);
        $request = new GetHumanStatus($model);

        new StatusAction()->execute($request);

        self::assertTrue($request->isCaptured());
    }

    public function testAPaymentThatWasNeverStartedIsNew(): void
    {
        $request = new GetHumanStatus(new ArrayObject([]));

        new StatusAction()->execute($request);

        self::assertTrue($request->isNew());
    }

    public function testItSupportsOnlyStatusRequestsWithAnArrayAccessModel(): void
    {
        $action = new StatusAction();

        self::assertTrue($action->supports(new GetHumanStatus(new ArrayObject([]))));
        self::assertFalse($action->supports(new Capture(new ArrayObject([]))));
        self::assertFalse($action->supports(new GetHumanStatus(new \stdClass())));
    }

    public function testItDoesNotDependOnAnApiSoItCannotAccidentallyCallTheNetwork(): void
    {
        self::assertFalse(
            new StatusAction() instanceof \Payum\Core\ApiAwareInterface,
            'A StatusAction nem kaphat API-t: ha kap, előbb-utóbb hálózatozni fog. '
            . 'A lekérdezés a SyncAction dolga.',
        );
    }
}
```

> Az utolsó teszt szokatlan — egy osztály *hiányzó* képességét állítja. Ez
> szándékos: a régi implementáció `StatusAction`-je `ApiAwareInterface` volt,
> és minden státuszolvasásnál lekérdezést indított. Ez a teszt szerkezetileg
> teszi lehetetlenné a visszaesést.

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/StatusMapTest.php tests/Unit/Action/StatusActionTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\StatusMap" not found`.

- [ ] **Step 3: A `StatusMap` és a `StatusAction` megírása**

`src/StatusMap.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePay\TransactionStatus;
use Payum\Core\Request\GetStatusInterface;

/**
 * SimplePay tranzakció-státusz → Payum státusz.
 *
 * A `match` KIMERÍTŐ, `default` ág nélkül: ha a protokoll-csomag új
 * `TransactionStatus` esetet vesz fel, ez fordítási hibát ad.
 *
 * A régi implementáció ehelyett egy 29 elemű, kisbetűs string-térképet
 * használt (`paid`, `captured`, `completed`, …), amiben a SimplePay 11
 * valódi státuszából csak három szerepelt. A `FINISHED` — a sikeres fizetés —
 * nem volt köztük, tehát `unknown`-ra képződött; a webhook-feldolgozó pedig
 * épp az `unknown` státuszt utasította el. Így minden sikeres fizetés
 * elutasításra került volna.
 */
final class StatusMap
{
    public static function apply(GetStatusInterface $request, ?TransactionStatus $status): void
    {
        if (null === $status) {
            $request->markNew();

            return;
        }

        match ($status) {
            TransactionStatus::Init,
            TransactionStatus::InPayment,
            TransactionStatus::InFraud => $request->markPending(),
            // A kétlépéses fizetést ez a csomag nem támogatja, tehát ez az
            // állapot nem várható. A leképezés mégis őszinte marad: az
            // AUTHORIZED lefoglalt, de le nem hívott összeg, nem "függőben".
            TransactionStatus::Authorized => $request->markAuthorized(),
            TransactionStatus::Finished => $request->markCaptured(),
            TransactionStatus::Cancelled,
            TransactionStatus::Timeout => $request->markCanceled(),
            TransactionStatus::NotAuthorized,
            TransactionStatus::Fraud => $request->markFailed(),
            TransactionStatus::Reversed,
            TransactionStatus::Refund => $request->markRefunded(),
        };
    }
}
```

`src/Action/StatusAction.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\StatusMap;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;

/**
 * Tiszta olvasás: a tárolt állapotot fordítja Payum státusszá.
 *
 * SZÁNDÉKOSAN NEM `ApiAwareInterface`. Ez az osztály nem tud hálózatozni,
 * és nem is szabad tudnia: a lekérdezés a `SyncAction` dolga. A régi
 * implementáció itt indított `/query` hívást minden státuszolvasásnál, ami
 * egy admin listaoldalon fizetésenként egy HTTP kérést jelentett volna.
 */
final class StatusAction implements ActionInterface
{
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var GetStatusInterface $request */
        $model = $request->getModel();

        /** @var \ArrayAccess<string, mixed> $model */
        StatusMap::apply($request, Details::fromModel($model)->state()->status);
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface
            && $request->getModel() instanceof \ArrayAccess;
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/StatusMapTest.php tests/Unit/Action/StatusActionTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/StatusMap.php src/Action/StatusAction.php tests/Unit/StatusMapTest.php tests/Unit/Action/StatusActionTest.php
git commit -m "feat(status): enum-alapú státusz-leképezés, hálózat nélküli StatusAction

Kimerítő match mind a 11 TransactionStatus esetre, default ág nélkül. A régi
string-térkép a FINISHED-et sem ismerte, tehát minden sikeres fizetés
unknown-ra képződött volna. A StatusAction nem ApiAware — a lekérdezés a
SyncAction dolga."
```

---

### Task 10: `TransitionGuard` — melyik státuszváltás fogadható el

**Files:**
- Create: `src/TransitionGuard.php`, `src/TransitionDecision.php`
- Test: `tests/Unit/TransitionGuardTest.php`

**Interfaces:**
- Consumes: `CodeConjure\SimplePay\TransactionStatus`
- Produces:
  - `enum TransitionDecision { case Apply; case Duplicate; case Rejected; }`
  - `TransitionGuard::decide(?TransactionStatus $current, TransactionStatus $incoming): TransitionDecision`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/TransitionGuardTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\TransitionDecision;
use CodeConjure\SimplePayPayum\TransitionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransitionGuardTest extends TestCase
{
    /** @return iterable<string, array{?TransactionStatus, TransactionStatus, TransitionDecision}> */
    public static function transitions(): iterable
    {
        yield 'első értesítés bármit elfogad' => [
            null, TransactionStatus::Finished, TransitionDecision::Apply,
        ];
        yield 'INIT-ből FINISHED' => [
            TransactionStatus::Init, TransactionStatus::Finished, TransitionDecision::Apply,
        ];
        yield 'INPAYMENT-ből NOTAUTHORIZED' => [
            TransactionStatus::InPayment, TransactionStatus::NotAuthorized, TransitionDecision::Apply,
        ];
        yield 'AUTHORIZED-ból FINISHED' => [
            TransactionStatus::Authorized, TransactionStatus::Finished, TransitionDecision::Apply,
        ];
        yield 'INFRAUD-ból FRAUD' => [
            TransactionStatus::InFraud, TransactionStatus::Fraud, TransitionDecision::Apply,
        ];
        yield 'ugyanaz a státusz duplikátum' => [
            TransactionStatus::Finished, TransactionStatus::Finished, TransitionDecision::Duplicate,
        ];
        yield 'INIT ismétlődése is duplikátum' => [
            TransactionStatus::Init, TransactionStatus::Init, TransitionDecision::Duplicate,
        ];
        yield 'FINISHED-ből REFUND elfogadható' => [
            TransactionStatus::Finished, TransactionStatus::Refund, TransitionDecision::Apply,
        ];
        yield 'FINISHED-ből REVERSED elfogadható' => [
            TransactionStatus::Finished, TransactionStatus::Reversed, TransitionDecision::Apply,
        ];
        yield 'FINISHED-ből CANCELLED nem' => [
            TransactionStatus::Finished, TransactionStatus::Cancelled, TransitionDecision::Rejected,
        ];
        yield 'FINISHED-ből INIT nem' => [
            TransactionStatus::Finished, TransactionStatus::Init, TransitionDecision::Rejected,
        ];
        yield 'CANCELLED után semmi' => [
            TransactionStatus::Cancelled, TransactionStatus::Finished, TransitionDecision::Rejected,
        ];
        yield 'TIMEOUT után semmi' => [
            TransactionStatus::Timeout, TransactionStatus::Finished, TransitionDecision::Rejected,
        ];
        yield 'NOTAUTHORIZED után semmi' => [
            TransactionStatus::NotAuthorized, TransactionStatus::Finished, TransitionDecision::Rejected,
        ];
        yield 'REFUND után semmi' => [
            TransactionStatus::Refund, TransactionStatus::Finished, TransitionDecision::Rejected,
        ];
    }

    #[DataProvider('transitions')]
    public function testItDecidesAccordingToTheTable(
        ?TransactionStatus $current,
        TransactionStatus $incoming,
        TransitionDecision $expected,
    ): void {
        self::assertSame($expected, new TransitionGuard()->decide($current, $incoming));
    }

    public function testARefundNotificationAfterASuccessfulPaymentIsNotRejected(): void
    {
        // A régi implementáció táblája SUCCESSFUL => [] volt: egy sikeres
        // fizetés után SEMMIT nem fogadott el, tehát a jóváírás-értesítést
        // is elutasította volna. Ez a teszt őrzi a javítást.
        self::assertSame(
            TransitionDecision::Apply,
            new TransitionGuard()->decide(TransactionStatus::Finished, TransactionStatus::Refund),
        );
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/TransitionGuardTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\TransitionDecision" not found`.

- [ ] **Step 3: A `TransitionDecision` és a `TransitionGuard` megírása**

`src/TransitionDecision.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

/**
 * Mit kezdjünk egy beérkezett státusszal.
 *
 * A `Duplicate` és a `Rejected` egyaránt azt jelenti, hogy az állapotot nem
 * írjuk felül — de nem ugyanaz a helyzet, és nem ugyanaz a naplószint.
 * Mindkettőre megy aláírt IPN-visszaigazolás.
 */
enum TransitionDecision
{
    case Apply;
    case Duplicate;
    case Rejected;
}
```

`src/TransitionGuard.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePay\TransactionStatus;

/**
 * Az átmenetek a `TransactionStatus` enumon vannak megfogalmazva, nem
 * kanonikus vödrökön — így nem lehet két különböző SimplePay állapotot
 * véletlenül azonosnak venni.
 *
 * | jelenlegi                                   | mi fogadható el       |
 * |---------------------------------------------|-----------------------|
 * | nincs még státusz                           | bármi                 |
 * | INIT, INPAYMENT, AUTHORIZED, INFRAUD        | bármi                 |
 * | FINISHED                                    | csak REFUND, REVERSED |
 * | egyéb végleges                              | semmi                 |
 *
 * A régi implementáció táblája `SUCCESSFUL => []`-t mondott: egy sikeres
 * fizetés után semmit nem fogadott el, tehát a jóváírás-értesítést sem.
 */
final class TransitionGuard
{
    public function decide(?TransactionStatus $current, TransactionStatus $incoming): TransitionDecision
    {
        if (null === $current) {
            return TransitionDecision::Apply;
        }

        if ($current === $incoming) {
            return TransitionDecision::Duplicate;
        }

        if (!$current->isFinal()) {
            return TransitionDecision::Apply;
        }

        if (TransactionStatus::Finished === $current && $this->isRefundOutcome($incoming)) {
            return TransitionDecision::Apply;
        }

        return TransitionDecision::Rejected;
    }

    private function isRefundOutcome(TransactionStatus $status): bool
    {
        return TransactionStatus::Refund === $status || TransactionStatus::Reversed === $status;
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/TransitionGuardTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/TransitionGuard.php src/TransitionDecision.php tests/Unit/TransitionGuardTest.php
git commit -m "feat: átmenet-őr a TransactionStatus enumon

A FINISHED-ből a REFUND és a REVERSED elfogadható — a régi tábla egy sikeres
fizetés után semmit nem fogadott el, tehát a jóváírás-értesítést is
elutasította volna. A duplikátum külön döntés, nem elutasítás."
```

---

### Task 11: `CaptureAction` — fizetés indítása és az újra-capture őr

**Files:**
- Create: `src/Action/CaptureAction.php`
- Test: `tests/Unit/Action/CaptureActionTest.php`, `tests/Unit/ApiFactory.php`

**Interfaces:**
- Consumes: `Api` (Task 8), `Details` (Task 7), `StartData` (Task 4), `TransactionState` (Task 6)
- Produces:
  - `new CaptureAction()` — paraméter nélküli konstruktor, `ActionInterface` + `ApiAwareInterface`, `apiClass = Api::class`
  - `ApiFactory::create(MockHttpClient $httpClient, string $merchant = 'PUBLICTESTHUF', string $secret = 'titok'): Api` (tesztsegéd)

- [ ] **Step 1: A tesztsegéd és a bukó teszt megírása**

`tests/Unit/ApiFactory.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\Client;
use CodeConjure\SimplePay\Config;
use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SimplePay\Signature;
use CodeConjure\SimplePayPayum\Api;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;

/**
 * Valódi protokoll-klienst épít egy mock PSR-18 kliens fölé.
 *
 * A `CodeConjure\SimplePay\Client` `final` és nincs interfésze — ez az 1.
 * fázis tudatos döntése —, tehát nem mockolható. Cserébe minden teszt a
 * valódi JSON-szerializáláson, a valódi HMAC-SHA384 aláíráson és a valódi
 * válasz-parse-oláson keresztül fut: egy elrontott mezőnév itt kiderül.
 */
final class ApiFactory
{
    public const string MERCHANT = 'PUBLICTESTHUF';
    public const string SECRET = 'FxDa5w314kLlNseq2sKuVwaqZshZT5d6';

    public static function create(MockHttpClient $httpClient): Api
    {
        $factory = new Psr17Factory();

        return new Api(
            new Client(
                new Config(self::MERCHANT, self::SECRET, Environment::Sandbox),
                $httpClient,
                $factory,
                $factory,
            ),
            self::MERCHANT,
            Environment::Sandbox,
            Currency::HUF,
        );
    }

    /**
     * A SimplePay minden választ aláír; a protokoll-kliens ellenőrzi is.
     * A mock válaszainak tehát valódi aláírást kell hordozniuk.
     */
    public static function signedResponse(string $body, int $status = 200): Response
    {
        return new Response(
            $status,
            ['Signature' => new Signature(self::SECRET)->sign($body)],
            $body,
        );
    }
}
```

> **Ellenőrizd az implementáció közben:** a protokoll-kliens
> `Client::post()`-ja ellenőrzi-e a *válasz* aláírását. Ha igen, a
> `signedResponse()` kötelező; ha nem, akkor is helyes, csak fölösleges.
> A `tests/Unit/` első futtatása megmondja. Ha a `Signature` osztály nem
> publikus a protokoll-csomagban, olvasd ki a `Config::signature()`-ból:
> `new Config(...)->signature()->sign($body)`.

`tests/Unit/Action/CaptureActionTest.php`:

```php
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

        self::assertNull($httpClient->getLastRequest(), 'Élő tranzakcióhoz nem szabad új /start kérést küldeni.');
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
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Action/CaptureActionTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Action\CaptureAction" not found`.

- [ ] **Step 3: A `CaptureAction` megírása**

`src/Action/CaptureAction.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePay\Exception\RequestException;
use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Capture;

/**
 * Elindítja a fizetést és átirányít a SimplePay fizetőoldalára.
 *
 * Automatikus újrapróbálás SEHOL nincs: egy vak `/start` ismétlés dupla
 * terhelést okozhatna a vásárló kártyáján. A hálózati és protokoll-hibák
 * változatlanul továbbdobódnak.
 */
final class CaptureAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var Capture $request */
        /** @var \ArrayAccess<string, mixed> $model */
        $model = $request->getModel();
        $details = Details::fromModel($model);
        $state = $details->state();

        // Újra-capture őr: ha a vevő visszalép és újraküld, ne indítsunk
        // második tranzakciót ugyanarra a fizetésre — irányítsuk vissza a
        // még élő fizetőoldalra. A régi implementáció itt új /start-ot
        // indított ugyanazzal az orderRef-fel.
        if ($state->isLive(new \DateTimeImmutable())) {
            /** @var string $paymentUrl az isLive() garantálja, hogy nem null */
            $paymentUrl = $state->paymentUrl;

            throw new HttpRedirect($paymentUrl);
        }

        $startData = $details->startData();

        try {
            $response = $this->api()->client->start($startData->toStartRequest());
        } catch (RequestException $exception) {
            $details->writeState($state->with(lastErrorCodes: $exception->codes()));

            throw $exception;
        }

        $details->writeState(new TransactionState(
            transactionId: $response->transactionId,
            status: TransactionStatus::Init,
            paymentUrl: $response->paymentUrl,
            timeout: $response->timeout,
            attempt: $startData->attempt,
            // Az IPN-napló egy korábbi, lejárt próbálkozásból is értékes:
            // megmutatja, hogy akkor mi történt. Nem dobjuk el.
            ipnLog: $state->ipnLog,
        ));

        throw new HttpRedirect($response->paymentUrl);
    }

    public function supports($request): bool
    {
        return $request instanceof Capture && $request->getModel() instanceof \ArrayAccess;
    }

    private function api(): Api
    {
        /** @var Api $api az ApiAwareTrait::setApi() garantálja a típust */
        $api = $this->api;

        return $api;
    }
}
```

> **A `TransactionState`-et itt teljesen újraépítjük, nem `with()`-eljük.**
> Egy új tranzakció nem örökölheti az előző `finishDate`-jét, `method`-ját
> vagy jóváírási mezőit — azok a régi tranzakcióról szóltak. Csak az
> IPN-napló megy át, mert az a fizetés egészének története.

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Action/CaptureActionTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS. Ha a `signedResponse()` fölösleges (a kliens nem ellenőrzi a
válasz-aláírást), a tesztek akkor is átmennek — hagyd benne.

- [ ] **Step 5: Commit**

```bash
git add src/Action/CaptureAction.php tests/Unit/Action/CaptureActionTest.php tests/Unit/ApiFactory.php
git commit -m "feat(action): fizetés indítása újra-capture őrrel

Ha a vevő visszalép és újraküld, a még élő tranzakció fizetőoldalára
irányítunk vissza, nem indítunk másodikat. Az attempt számláló csak akkor
lép, amikor tényleg elindult egy tranzakció. Automatikus újrapróbálás nincs."
```

---

### Task 12: IPN-feloldás — `GetHttpRequestWithHeaders`, `ResolveSimplePayIpn`, `ResolveIpnAction`

**Files:**
- Create: `src/Request/GetHttpRequestWithHeaders.php`, `src/Request/ResolveSimplePayIpn.php`, `src/Action/ResolveIpnAction.php`
- Test: `tests/Unit/Action/ResolveIpnActionTest.php`, `tests/Unit/IpnFixture.php`

**Interfaces:**
- Consumes: `Api` (Task 8)
- Produces:
  - `final class GetHttpRequestWithHeaders extends GetHttpRequest { public array $headers = []; }`
  - `new ResolveSimplePayIpn(string $rawBody, string $signature)`; `setMessage(IpnMessage): void`; `getMessage(): IpnMessage`
  - `new ResolveIpnAction()` — `ActionInterface` + `ApiAwareInterface`
  - `IpnFixture::finishedBody(): string`, `IpnFixture::sign(string $body): string` (tesztsegéd)

- [ ] **Step 1: A fixture, a tesztsegéd és a bukó teszt megírása**

`tests/Fixtures/ipn_finished.json` — a hivatalos SimplePay dokumentáció IPN-mintája, változatlanul:

```json
{"salt":"223G0O18VAqdLhQYbJz73adT36YzLtak","orderRef":"101010515680292482600","method":"CARD","merchant":"PUBLICTESTHUF","finishDate":"2019-09-09T14:46:18+0200","paymentDate":"2019-09-09T14:41:13+0200","transactionId":99844942,"status":"FINISHED"}
```

> **Ez a fájl byte-pontosan marad.** Nem formázzuk újra, nem tesszük bele
> sortörést: az aláírás a nyers byte-sorozat fölött számolódik, és a
> visszaigazolás is ezekből a byte-okból épül. Két megfigyelés, amit a
> tesztnek rögzítenie kell: a `transactionId` **JSON-szám**, nem string;
> és **nincs benne `total` és `currency`** — az IPN-ből összeg nem egyeztethető.

`tests/Unit/IpnFixture.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\Signature;

final class IpnFixture
{
    public static function finishedBody(): string
    {
        $path = __DIR__ . '/../Fixtures/ipn_finished.json';
        $body = file_get_contents($path);

        if (false === $body) {
            throw new \RuntimeException(sprintf('Nem olvasható a fixture: %s', $path));
        }

        return trim($body);
    }

    /**
     * A dokumentáció mintájához nem tartozik érvényes aláírás — a teszt
     * saját titkával számoljuk újra.
     */
    public static function sign(string $body): string
    {
        return new Signature(ApiFactory::SECRET)->sign($body);
    }

    /** @param array<string, mixed> $overrides */
    public static function bodyWith(array $overrides): string
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(self::finishedBody(), true, 512, \JSON_THROW_ON_ERROR);

        return json_encode(array_merge($decoded, $overrides), \JSON_THROW_ON_ERROR);
    }
}
```

`tests/Unit/Action/ResolveIpnActionTest.php`:

```php
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
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Action/ResolveIpnActionTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Request\ResolveSimplePayIpn" not found`.

- [ ] **Step 3: A három osztály megírása**

`src/Request/GetHttpRequestWithHeaders.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Request;

use Payum\Core\Request\GetHttpRequest;

/**
 * A Payum `GetHttpRequest` fejlécekkel, deklarált property-ben.
 *
 * A `GetHttpRequest` osztályon `#[\AllowDynamicProperties]` van, és a Symfony-híd
 * (`Payum\Bundle\PayumBundle\Action\GetHttpRequestAction::updateRequest()`)
 * dinamikusan tűzi rá a `headers` értéket — deklarált property nélkül. Ez
 * phpstan level 9 alatt nem definiált property olvasása.
 *
 * A híd `supports()`-a `instanceof GetHttpRequest`-et néz, tehát ezt az
 * alosztályt ugyanaz az action szolgálja ki, és ugyanaz az értékadás tölti
 * a `headers`-t — csak most tipizált, deklarált property-be.
 *
 * ISMERT KORLÁT: a `Payum\Core\Bridge\PlainPhp\Action\GetHttpRequestAction`
 * nem tölti a fejléceket. Ilyen környezetben a `$headers` üres marad, és a
 * `NotifyAction` hangosan elbukik hiányzó `Signature` fejléc hibával.
 * Az IPN feldolgozása Symfony-hidat igényel.
 *
 * @var array<string, list<string|null>> $headers
 */
final class GetHttpRequestWithHeaders extends GetHttpRequest
{
    /** @var array<string, list<string|null>> */
    public array $headers = [];
}
```

`src/Request/ResolveSimplePayIpn.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Request;

use CodeConjure\SimplePay\Ipn\IpnMessage;

/**
 * Modell nélküli Payum request: ellenőriz és parse-ol, semmit nem ír.
 *
 * A hívó ezzel dönti el, MELYIK fizetéshez tartozik egy beérkezett IPN,
 * mielőtt modellt adna a `Notify`-nak. Enélkül a hívónak az aláíratlan
 * törzsből kellene `orderRef`-et olvasnia — vagyis egy még nem hitelesített
 * adatra alapozva keresne rekordot az adatbázisban.
 */
final class ResolveSimplePayIpn
{
    private ?IpnMessage $message = null;

    public function __construct(
        public readonly string $rawBody,
        public readonly string $signature,
    ) {
    }

    public function setMessage(IpnMessage $message): void
    {
        $this->message = $message;
    }

    public function getMessage(): IpnMessage
    {
        return $this->message ?? throw new \LogicException(
            'A ResolveSimplePayIpn üzenete csak a gateway végrehajtása után olvasható.',
        );
    }
}
```

`src/Action/ResolveIpnAction.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Request\ResolveSimplePayIpn;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;

/**
 * Ellenőrzi az IPN aláírását és a merchant egyezését, majd kiadja a
 * parse-olt üzenetet — állapotot nem ír.
 *
 * Az aláírás így kétszer számolódik (itt és a `NotifyAction`-ben).
 * HMAC-SHA384 egy néhány száz bájtos törzsön: elhanyagolható ár azért, hogy
 * minden kriptográfia a Payum és a protokoll rétegben maradjon, és a hívó
 * soha ne olvasson aláíratlan adatot.
 */
final class ResolveIpnAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var ResolveSimplePayIpn $request */
        /** @var Api $api */
        $api = $this->api;

        $request->setMessage($api->client->ipn($request->rawBody, $request->signature)->message());
    }

    public function supports($request): bool
    {
        return $request instanceof ResolveSimplePayIpn;
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Action/ResolveIpnActionTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Request tests/Fixtures/ipn_finished.json tests/Unit/IpnFixture.php src/Action/ResolveIpnAction.php tests/Unit/Action/ResolveIpnActionTest.php
git commit -m "feat(ipn): modell nélküli feloldás és fejléces GetHttpRequest

A ResolveSimplePayIpn ellenőriz és parse-ol, hogy a hívó ne aláíratlan
törzsből keressen fizetést. A GetHttpRequestWithHeaders deklarált
property-be teszi a fejléceket — a Payum ezeket dinamikusan tűzi rá, ami
phpstan level 9 alatt nem definiált property olvasása volna.

A fixture a hivatalos dokumentáció IPN-mintája: a transactionId JSON-szám,
és nincs benne total/currency — az IPN-ből összeg nem egyeztethető."
```

---

### Task 13: `NotifyAction` — az IPN feldolgozása és az aláírt visszaigazolás

**Files:**
- Create: `src/Action/NotifyAction.php`
- Test: `tests/Unit/Action/NotifyActionTest.php`

**Interfaces:**
- Consumes: `Api` (Task 8), `Details` (Task 7), `TransitionGuard`, `TransitionDecision` (Task 10), `GetHttpRequestWithHeaders` (Task 12), `IpnOutcome` (Task 5), `MissingSignatureHeaderException` (Task 2)
- Produces: `new NotifyAction(TransitionGuard $guard = new TransitionGuard(), LoggerInterface $logger = new NullLogger())` — `ActionInterface` + `ApiAwareInterface` + `GatewayAwareInterface`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Action/NotifyActionTest.php`:

```php
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
use Http\Mock\Client as MockHttpClient;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayInterface;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Notify;
use PHPUnit\Framework\TestCase;

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
        $gateway = $this->createMock(GatewayInterface::class);
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
        $model = new ArrayObject([Details::STATE_KEY => ['transactionId' => '99844942', 'status' => 'INIT']]);

        $reply = $this->notify($this->action($body), $model);

        self::assertSame(200, $reply->getStatusCode());
        self::assertStringContainsString('"receiveDate"', $reply->getContent());
        self::assertStringStartsWith($body, substr($reply->getContent(), 0, strlen($body) - 1));
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

    public function testItSupportsOnlyNotifyRequestsWithAnArrayAccessModel(): void
    {
        $action = $this->action(IpnFixture::finishedBody());

        self::assertTrue($action->supports(new Notify(new ArrayObject([]))));
        self::assertFalse($action->supports(new Capture(new ArrayObject([]))));
        self::assertFalse($action->supports(new Notify(new \stdClass())));
    }
}
```

> **A legfontosabb állítások ebben a taskban:** a duplikátumra és a tiltott
> átmenetre is **megy** aláírt visszaigazolás (`200`), és **nem megy** hamis
> aláírásra. A régi `SimplePayWebhookProcessor::reject()` kivételt dobott és
> semmit nem válaszolt — a SimplePay ezt örök ismétlésként élte volna meg.

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Action/NotifyActionTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Action\NotifyAction" not found`.

- [ ] **Step 3: A `NotifyAction` megírása**

`src/Action/NotifyAction.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePay\Ipn\IpnMessage;
use CodeConjure\SimplePay\TransactionStatus;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\MissingSignatureHeaderException;
use CodeConjure\SimplePayPayum\Model\IpnOutcome;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SimplePayPayum\Request\GetHttpRequestWithHeaders;
use CodeConjure\SimplePayPayum\TransitionDecision;
use CodeConjure\SimplePayPayum\TransitionGuard;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Notify;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Feldolgozza a SimplePay értesítését (IPN), és aláírt visszaigazolást ad.
 *
 * A visszaigazolás MINDIG elmegy, ha az aláírás és a merchant stimmel — akkor
 * is, ha az állapotgépünk nem tud mit kezdeni a beérkezett státusszal. A
 * SimplePay addig ismétli az értesítést, amíg meg nem kapja az aláírt választ;
 * egy hitelesített üzenet vissza nem igazolása állapotgép-nézeteltérésből
 * végtelen ismétlési ciklust csinál. A régi implementáció itt kivételt dobott
 * és semmit nem válaszolt.
 *
 * Visszaigazolás NEM megy érvénytelen aláírásra és idegen merchantra —
 * mindkettő kivétel, amit a hívó controller 400-as válasszá alakít.
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use ApiAwareTrait;
    use GatewayAwareTrait;

    public function __construct(
        private readonly TransitionGuard $transitionGuard = new TransitionGuard(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->apiClass = Api::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var Notify $request */
        /** @var \ArrayAccess<string, mixed> $model */
        $model = $request->getModel();
        $details = Details::fromModel($model);

        $httpRequest = new GetHttpRequestWithHeaders();
        $this->gateway->execute($httpRequest);

        /** @var Api $api */
        $api = $this->api;

        $confirmation = $api->client->ipn(
            $httpRequest->content,
            $this->signatureHeader($httpRequest),
        );

        $message = $confirmation->message();
        $state = $details->state();
        $decision = $this->transitionGuard->decide($state->status, $message->status);

        $outcome = match ($decision) {
            TransitionDecision::Apply => IpnOutcome::Applied,
            TransitionDecision::Duplicate => IpnOutcome::Duplicate,
            TransitionDecision::Rejected => IpnOutcome::Rejected,
        };

        $updated = TransitionDecision::Apply === $decision
            ? $this->applied($state, $message)
            : $state;

        $updated = $updated->withIpnEntry(
            new \DateTimeImmutable(),
            $message->transactionId,
            $message->status,
            $outcome,
        );

        $details->writeState($updated);

        $this->log($outcome, $message, $state->status, $updated);

        throw new HttpResponse(
            $confirmation->responseBody(),
            200,
            ['Signature' => $confirmation->responseSignature()],
        );
    }

    public function supports($request): bool
    {
        return $request instanceof Notify && $request->getModel() instanceof \ArrayAccess;
    }

    private function applied(TransactionState $state, IpnMessage $message): TransactionState
    {
        return $state->with(
            transactionId: $message->transactionId,
            status: $message->status,
            method: $message->method,
            paymentDate: $message->paymentDate,
            finishDate: $message->finishDate,
        );
    }

    /**
     * A Symfony-híd kisbetűs kulcsokkal adja a fejléceket, de más hidak
     * nem feltétlenül — ezért kisbetűsítve keresünk. Hiányzó vagy üres
     * érték hangos hiba: aláírás nélkül a törzs hitelesíthetetlen, tehát
     * nincs mit feldolgozni. Alapértelmezés nincs.
     */
    private function signatureHeader(GetHttpRequestWithHeaders $httpRequest): string
    {
        foreach ($httpRequest->headers as $name => $values) {
            if ('signature' !== strtolower($name)) {
                continue;
            }

            foreach ($values as $value) {
                if (is_string($value) && '' !== trim($value)) {
                    return $value;
                }
            }
        }

        throw new MissingSignatureHeaderException(
            'A SimplePay értesítésből hiányzik vagy üres a "Signature" fejléc. '
            . 'Aláírás nélkül a törzs nem hitelesíthető. Ha ez Symfonyn kívüli '
            . 'környezetben történt: a Payum PlainPhp hídja nem tölti a fejléceket, '
            . 'az IPN feldolgozása Symfony-hidat igényel.',
        );
    }

    private function log(
        IpnOutcome $outcome,
        IpnMessage $message,
        ?TransactionStatus $previousStatus,
        TransactionState $state,
    ): void {
        $context = [
            'event' => 'simplepay.ipn.processed',
            'outcome' => $outcome->value,
            'transaction_id' => $message->transactionId,
            'order_ref' => $message->orderRef,
            'previous_status' => $previousStatus?->value,
            'incoming_status' => $message->status->value,
            'repeat_count' => $state->ipnLog[count($state->ipnLog) - 1]->repeatCount,
        ];

        match ($outcome) {
            IpnOutcome::Applied => $this->logger->info('SimplePay értesítés feldolgozva.', $context),
            // A duplikátum warning, nem notice: a SimplePay addig ismétel,
            // amíg el nem fogadja a visszaigazolásunkat. Egy növekvő
            // repeat_count azt jelenti, hogy NEM fogadja el — és a jelenleg
            // egyetlen ismert gyanúsított a receiveDate időbélyeg formátuma.
            IpnOutcome::Duplicate => $this->logger->warning(
                'SimplePay értesítés ismétlődött — lehet, hogy a visszaigazolásunkat nem fogadták el.',
                $context,
            ),
            IpnOutcome::Rejected => $this->logger->error(
                'SimplePay értesítés státusza nem illeszkedik a tárolt állapothoz; nem alkalmaztuk.',
                $context,
            ),
        };
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Action/NotifyActionTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Action/NotifyAction.php tests/Unit/Action/NotifyActionTest.php
git commit -m "feat(action): IPN-feldolgozás aláírt visszaigazolással

A visszaigazolás mindig elmegy, ha az aláírás és a merchant stimmel — a
duplikátumra és a tiltott átmenetre is. A régi implementáció itt kivételt
dobott és semmit nem válaszolt, amit a SimplePay örök ismétlésként élt volna
meg. Hiányzó Signature fejlécre hangos hiba, nem aláírás nélküli feldolgozás."
```

---

### Task 14: `SyncAction` — a valódi állapot lekérdezése

**Files:**
- Create: `src/Action/SyncAction.php`
- Test: `tests/Unit/Action/SyncActionTest.php`

**Interfaces:**
- Consumes: `Api` (Task 8), `Details` (Task 7), `TransitionGuard` (Task 10), `TransactionNotFoundException` (Task 2)
- Produces: `new SyncAction(TransitionGuard $guard = new TransitionGuard(), LoggerInterface $logger = new NullLogger())` — `ActionInterface` + `ApiAwareInterface`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Action/SyncActionTest.php`:

```php
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
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Action/SyncActionTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Action\SyncAction" not found`.

- [ ] **Step 3: A `SyncAction` megírása**

`src/Action/SyncAction.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePay\Request\QueryRequest;
use CodeConjure\SimplePay\Response\Transaction;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\TransactionNotFoundException;
use CodeConjure\SimplePayPayum\Model\TransactionState;
use CodeConjure\SimplePayPayum\TransitionDecision;
use CodeConjure\SimplePayPayum\TransitionGuard;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Sync;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Lekérdezi a tranzakció valódi állapotát a SimplePay-től.
 *
 * ITT, ÉS CSAK ITT történik hálózati hívás státuszolvasáskor. A `StatusAction`
 * tiszta olvasás; a régi implementáció összemosta a kettőt, és minden
 * státuszolvasásnál lekérdezést indított.
 *
 * A protokoll-csomag minden lekérdezésnél `detailed: true`-t küld — nem
 * választható. Ennek adatvédelmi ára van: a válasz a vevő nevét, e-mail
 * címét és számlázási címét is tartalmazza, még ha a `Transaction` ezeket
 * el is dobja. Ne kapcsolj be HTTP-szintű törzs-naplózást éles környezetben.
 */
final class SyncAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    public function __construct(
        private readonly TransitionGuard $transitionGuard = new TransitionGuard(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->apiClass = Api::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var Sync $request */
        /** @var \ArrayAccess<string, mixed> $model */
        $model = $request->getModel();
        $details = Details::fromModel($model);
        $state = $details->state();

        $transactionId = $state->transactionId;
        $orderRef = null;

        if (null === $transactionId) {
            // Nincs tranzakció-azonosítónk: a hivatkozás az egyetlen fogódzó.
            // Ha az sincs, a startData() hangos hibát dob — egy sosem
            // előkészített fizetést nincs mihez szinkronizálni.
            $orderRef = $details->startData()->orderRef;
        }

        $response = $this->api()->client->query(new QueryRequest(
            transactionIds: null === $transactionId ? [] : [$transactionId],
            orderRefs: null === $orderRef ? [] : [$orderRef],
        ));

        $transaction = null === $orderRef ? $response->first() : $response->byOrderRef($orderRef);

        if (null === $transaction) {
            throw new TransactionNotFoundException(sprintf(
                'A SimplePay nem ismer tranzakciót erre az azonosítóra: "%s".',
                $transactionId ?? $orderRef,
            ));
        }

        $details->writeState($this->merge($details, $transaction));
    }

    public function supports($request): bool
    {
        return $request instanceof Sync && $request->getModel() instanceof \ArrayAccess;
    }

    private function merge(Details $details, Transaction $transaction): TransactionState
    {
        $state = $details->state();
        $decision = $this->transitionGuard->decide($state->status, $transaction->status);

        $common = [
            'transactionId' => $transaction->transactionId,
            'lastCheckedAt' => new \DateTimeImmutable(),
            'remainingTotal' => $transaction->remainingTotal?->minorUnits,
        ];

        if (TransitionDecision::Apply !== $decision) {
            if (TransitionDecision::Rejected === $decision) {
                $this->logger->error('A SimplePay lekérdezés státusza nem illeszkedik a tárolt állapothoz; nem alkalmaztuk.', [
                    'event' => 'simplepay.sync.rejected',
                    'transaction_id' => $transaction->transactionId,
                    'stored_status' => $state->status?->value,
                    'queried_status' => $transaction->status->value,
                ]);
            }

            return $state->with(
                transactionId: $common['transactionId'],
                lastCheckedAt: $common['lastCheckedAt'],
                remainingTotal: $common['remainingTotal'],
            );
        }

        return $state->with(
            transactionId: $common['transactionId'],
            status: $transaction->status,
            lastCheckedAt: $common['lastCheckedAt'],
            method: $transaction->method,
            paymentDate: $transaction->paymentDate,
            finishDate: $transaction->finishDate,
            remainingTotal: $common['remainingTotal'],
        );
    }

    private function api(): Api
    {
        /** @var Api $api */
        $api = $this->api;

        return $api;
    }
}
```

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Action/SyncActionTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Action/SyncAction.php tests/Unit/Action/SyncActionTest.php
git commit -m "feat(action): állapot-lekérdezés, egyedül itt van hálózati hívás

Tranzakció-azonosító alapján kérdez, annak híján a hivatkozás alapján. Az
ismeretlen tranzakció hangos hiba, nem néma null. A tiltott átmenet itt sem
írja felül a tárolt állapotot, de a lekérdezés idejét rögzítjük."
```

---

### Task 15: `RefundAction` — jóváírás

**Files:**
- Create: `src/Action/RefundAction.php`
- Test: `tests/Unit/Action/RefundActionTest.php`

**Interfaces:**
- Consumes: `Api` (Task 8), `Details` (Task 7)
- Produces: `new RefundAction()` — `ActionInterface` + `ApiAwareInterface`

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/Action/RefundActionTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Action;

use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Details;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Tests\Unit\ApiFactory;
use CodeConjure\SimplePayPayum\Tests\Unit\Model\StartDataFactory;
use Http\Mock\Client as MockHttpClient;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\Capture;
use Payum\Core\Request\Refund;
use PHPUnit\Framework\TestCase;

final class RefundActionTest extends TestCase
{
    private static function refundBody(): string
    {
        return json_encode([
            'merchant' => ApiFactory::MERCHANT,
            'orderRef' => 'EZ-2026-0042-17-1',
            'transactionId' => 509007600,
            'refundTransactionId' => 509007601,
            'refundTotal' => 500,
            'remainingTotal' => 500,
            'currency' => 'HUF',
            'salt' => 'só',
        ], \JSON_THROW_ON_ERROR);
    }

    private function action(MockHttpClient $httpClient): RefundAction
    {
        $action = new RefundAction();
        $action->setApi(ApiFactory::create($httpClient));

        return $action;
    }

    /** @return ArrayObject<string, mixed> */
    private static function model(int $amount): ArrayObject
    {
        return new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => ['transactionId' => '509007600', 'status' => 'FINISHED'],
            Details::REFUND_KEY => ['amount' => $amount],
        ]);
    }

    public function testItRefundsTheAmountWrittenIntoTheDetails(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::refundBody()));

        $model = self::model(500);

        $this->action($httpClient)->execute(new Refund($model));

        $sent = json_decode((string) $httpClient->getLastRequest()?->getBody(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($sent);
        self::assertSame('500', $sent['refundTotal']);
        self::assertSame('HUF', $sent['currency']);
        self::assertSame('509007600', $sent['transactionId']);
    }

    public function testItRecordsTheRefundResultInTheState(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::refundBody()));

        $model = self::model(500);

        $this->action($httpClient)->execute(new Refund($model));

        $state = Details::fromModel($model)->state();

        self::assertSame('509007601', $state->refundTransactionId);
        self::assertSame(500, $state->refundTotal);
        self::assertSame(500, $state->remainingTotal);
    }

    public function testItDoesNotChangeTheStatusBecauseOnlyTheIpnOrAQueryDecidesThat(): void
    {
        $httpClient = new MockHttpClient();
        $httpClient->addResponse(ApiFactory::signedResponse(self::refundBody()));

        $model = self::model(500);

        $this->action($httpClient)->execute(new Refund($model));

        self::assertSame('FINISHED', Details::fromModel($model)->state()->status?->value);
    }

    public function testItRefusesToDefaultTheAmountToTheFullTotal(): void
    {
        $model = new ArrayObject([
            Details::REQUEST_KEY => StartDataFactory::array(),
            Details::STATE_KEY => ['transactionId' => '509007600', 'status' => 'FINISHED'],
        ]);

        $this->expectException(MissingDetailsException::class);
        $this->expectExceptionMessage(Details::REFUND_KEY);

        $this->action(new MockHttpClient())->execute(new Refund($model));
    }

    public function testItRefusesToRefundAPaymentThatWasNeverStarted(): void
    {
        $model = new ArrayObject([Details::REFUND_KEY => ['amount' => 500]]);

        $this->expectException(MissingDetailsException::class);

        $this->action(new MockHttpClient())->execute(new Refund($model));
    }

    public function testItSupportsOnlyRefundRequestsWithAnArrayAccessModel(): void
    {
        $action = $this->action(new MockHttpClient());

        self::assertTrue($action->supports(new Refund(new ArrayObject([]))));
        self::assertFalse($action->supports(new Capture(new ArrayObject([]))));
        self::assertFalse($action->supports(new Refund(new \stdClass())));
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/Action/RefundActionTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\Action\RefundAction" not found`.

- [ ] **Step 3: A `RefundAction` megírása**

`src/Action/RefundAction.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use CodeConjure\SimplePay\Money;
use CodeConjure\SimplePay\Request\RefundRequest;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Details;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Refund;

/**
 * Jóváírást indít.
 *
 * A Payum `Refund` requestnek nincs összeg mezője, ezért a hívó a
 * `simplepay_refund.amount` kulcsba írja a szándékot — a pénznem valódi
 * alegységében. Hiányzó összeg hangos hiba: NINCS olyan alapértelmezés,
 * hogy „akkor a teljes összeg". Egy jóváírás összegét nem találjuk ki.
 *
 * A státuszt ez az action NEM változtatja meg. Hogy a tranzakció valóban
 * `REFUND` állapotba került-e, azt az IPN vagy egy `Sync` dönti el — a
 * jóváírás elindítása és a jóváírás beállása két különböző esemény.
 */
final class RefundAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var Refund $request */
        /** @var \ArrayAccess<string, mixed> $model */
        $model = $request->getModel();
        $details = Details::fromModel($model);

        $amount = $details->refundAmount();
        $state = $details->state();

        /** @var Api $api */
        $api = $this->api;

        $response = $api->client->refund(new RefundRequest(
            refundTotal: Money::fromMinorUnits($amount, $api->currency),
            orderRef: null === $state->transactionId ? $details->startData()->orderRef : null,
            transactionId: $state->transactionId,
        ));

        $details->writeState($state->with(
            refundTransactionId: $response->refundTransactionId,
            refundTotal: $response->refundTotal->minorUnits,
            remainingTotal: $response->remainingTotal->minorUnits,
        ));
    }

    public function supports($request): bool
    {
        return $request instanceof Refund && $request->getModel() instanceof \ArrayAccess;
    }
}
```

> **Figyelem:** ha nincs `transactionId`, a `startData()` hangos hibát dob —
> ez a helyes viselkedés: egy sosem elindított fizetést nem lehet jóváírni.
> A `testItRefusesToRefundAPaymentThatWasNeverStarted` ezt rögzíti.

- [ ] **Step 4: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit tests/Unit/Action/RefundActionTest.php
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: PASS, phpstan és ECS tiszta.

- [ ] **Step 5: Commit**

```bash
git add src/Action/RefundAction.php tests/Unit/Action/RefundActionTest.php
git commit -m "feat(action): jóváírás a details-be írt összeg alapján

Nincs olyan alapértelmezés, hogy \"akkor a teljes összeg\" — egy jóváírás
összegét nem találjuk ki. A státuszt nem írjuk át: hogy a tranzakció
valóban REFUND állapotba került-e, azt az IPN vagy egy Sync dönti el."
```

---

### Task 16: `SimplePayGatewayFactory` és a Symfony Bundle

**Files:**
- Create: `src/SimplePayGatewayFactory.php`, `src/Bundle/SimplePayPayumBundle.php`, `src/Bundle/DependencyInjection/SimplePayPayumExtension.php`, `src/Bundle/Builder/SimplePayGatewayFactoryBuilder.php`, `src/Bundle/Resources/config/services.xml`
- Test: `tests/Unit/SimplePayGatewayFactoryTest.php`

**Interfaces:**
- Consumes: minden korábbi task
- Produces:
  - `new SimplePayGatewayFactory(ClientInterface $httpClient, RequestFactoryInterface $requestFactory, StreamFactoryInterface $streamFactory, LoggerInterface $logger = new NullLogger(), array $defaultConfig = [], ?GatewayFactoryInterface $coreGatewayFactory = null)`
  - Gateway config kulcsok: `merchant`, `secretKey`, `environment`, `currency`
  - `new SimplePayGatewayFactoryBuilder(...)` — `payum.gateway_factory_builder` taggel

- [ ] **Step 1: A bukó teszt megírása**

`tests/Unit/SimplePayGatewayFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SimplePay\Exception\ConfigurationException;
use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Action\ResolveIpnAction;
use CodeConjure\SimplePayPayum\Action\StatusAction;
use CodeConjure\SimplePayPayum\Action\SyncAction;
use CodeConjure\SimplePayPayum\Api;
use CodeConjure\SimplePayPayum\Exception\InvalidGatewayConfigException;
use CodeConjure\SimplePayPayum\SimplePayGatewayFactory;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Payum\Core\Bridge\Spl\ArrayObject;
use PHPUnit\Framework\TestCase;

final class SimplePayGatewayFactoryTest extends TestCase
{
    private function factory(): SimplePayGatewayFactory
    {
        $psr17 = new Psr17Factory();

        return new SimplePayGatewayFactory(new MockHttpClient(), $psr17, $psr17);
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        return [
            'merchant' => 'PUBLICTESTHUF',
            'secretKey' => 'FxDa5w314kLlNseq2sKuVwaqZshZT5d6',
            'environment' => 'sandbox',
            'currency' => 'HUF',
        ];
    }

    public function testItNamesItselfSimplepay(): void
    {
        $config = $this->factory()->createConfig(self::config());

        self::assertSame('simplepay', $config['payum.factory_name']);
        self::assertSame('SimplePay', $config['payum.factory_title']);
    }

    public function testItRegistersEverySimplePayAction(): void
    {
        $config = $this->factory()->createConfig(self::config());

        self::assertInstanceOf(CaptureAction::class, $config['payum.action.capture']);
        self::assertInstanceOf(NotifyAction::class, $config['payum.action.notify']);
        self::assertInstanceOf(StatusAction::class, $config['payum.action.status']);
        self::assertInstanceOf(SyncAction::class, $config['payum.action.sync']);
        self::assertInstanceOf(RefundAction::class, $config['payum.action.refund']);
        self::assertInstanceOf(ResolveIpnAction::class, $config['payum.action.resolve_ipn']);
    }

    public function testItBuildsAnApiFromTheGatewayConfiguration(): void
    {
        $config = $this->factory()->createConfig(self::config());
        $api = $config['payum.api'](ArrayObject::ensureArrayObject($config));

        self::assertInstanceOf(Api::class, $api);
        self::assertSame('PUBLICTESTHUF', $api->merchant);
        self::assertSame(Environment::Sandbox, $api->environment);
        self::assertSame(Currency::HUF, $api->currency);
    }

    public function testAnUnknownEnvironmentIsRejectedByName(): void
    {
        $config = self::config();
        $config['environment'] = 'staging';

        $built = $this->factory()->createConfig($config);

        $this->expectException(InvalidGatewayConfigException::class);
        $this->expectExceptionMessage('staging');

        $built['payum.api'](ArrayObject::ensureArrayObject($built));
    }

    public function testAnUnknownCurrencyIsRejectedByName(): void
    {
        $config = self::config();
        $config['currency'] = 'GBP';

        $built = $this->factory()->createConfig($config);

        $this->expectException(InvalidGatewayConfigException::class);
        $this->expectExceptionMessage('GBP');

        $built['payum.api'](ArrayObject::ensureArrayObject($built));
    }

    public function testAMissingMerchantIsRejectedByTheProtocolPackageNotSilentlyAccepted(): void
    {
        $config = self::config();
        $config['merchant'] = '';

        $built = $this->factory()->createConfig($config);

        $this->expectException(ConfigurationException::class);

        $built['payum.api'](ArrayObject::ensureArrayObject($built));
    }

    public function testAMissingSecretKeyIsRejected(): void
    {
        $config = self::config();
        $config['secretKey'] = '';

        $built = $this->factory()->createConfig($config);

        $this->expectException(ConfigurationException::class);

        $built['payum.api'](ArrayObject::ensureArrayObject($built));
    }
}
```

- [ ] **Step 2: Futtasd, győződj meg róla, hogy bukik**

```bash
vendor/bin/phpunit tests/Unit/SimplePayGatewayFactoryTest.php
```

Elvárt: FAIL, `Class "CodeConjure\SimplePayPayum\SimplePayGatewayFactory" not found`.

- [ ] **Step 3: A gateway factory megírása**

`src/SimplePayGatewayFactory.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use CodeConjure\SimplePay\Client;
use CodeConjure\SimplePay\Config;
use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Environment;
use CodeConjure\SimplePayPayum\Action\CaptureAction;
use CodeConjure\SimplePayPayum\Action\NotifyAction;
use CodeConjure\SimplePayPayum\Action\RefundAction;
use CodeConjure\SimplePayPayum\Action\ResolveIpnAction;
use CodeConjure\SimplePayPayum\Action\StatusAction;
use CodeConjure\SimplePayPayum\Action\SyncAction;
use CodeConjure\SimplePayPayum\Exception\InvalidGatewayConfigException;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use Payum\Core\GatewayFactoryInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A `simplepay` Payum gateway összeszerelése.
 *
 * Négy konfigurációs kulcsot olvas: `merchant`, `secretKey`, `environment`,
 * `currency`. A `currency` azért kell, mert a SimplePay merchant azonosító
 * pénznemhez kötött.
 *
 * A csomag NEM hoz PSR-18 klienst — azt az alkalmazás adja be. A protokoll-
 * csomag konstruktora validálja a merchantot és a titkot; nem építünk fölé
 * saját, elnézőbb ellenőrzést.
 */
final class SimplePayGatewayFactory extends GatewayFactory
{
    /** @param array<string, mixed> $defaultConfig */
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
        array $defaultConfig = [],
        ?GatewayFactoryInterface $coreGatewayFactory = null,
    ) {
        parent::__construct($defaultConfig, $coreGatewayFactory);
    }

    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => 'simplepay',
            'payum.factory_title' => 'SimplePay',
            'payum.action.capture' => new CaptureAction(),
            'payum.action.notify' => new NotifyAction(new TransitionGuard(), $this->logger),
            'payum.action.status' => new StatusAction(),
            'payum.action.sync' => new SyncAction(new TransitionGuard(), $this->logger),
            'payum.action.refund' => new RefundAction(),
            'payum.action.resolve_ipn' => new ResolveIpnAction(),
            'payum.api' => function (ArrayObject $config): Api {
                $merchant = $this->string($config, 'merchant');
                $environment = $this->enum(Environment::class, $this->string($config, 'environment'), 'environment');
                $currency = $this->enum(Currency::class, $this->string($config, 'currency'), 'currency');

                return new Api(
                    new Client(
                        new Config($merchant, $this->string($config, 'secretKey'), $environment),
                        $this->httpClient,
                        $this->requestFactory,
                        $this->streamFactory,
                    ),
                    $merchant,
                    $environment,
                    $currency,
                );
            },
        ]);

        parent::populateConfig($config);
    }

    private function string(ArrayObject $config, string $key): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     */
    private function enum(string $enum, string $value, string $key): \BackedEnum
    {
        return $enum::tryFrom($value) ?? throw new InvalidGatewayConfigException(sprintf(
            'A SimplePay gateway "%s" beállításának "%s" értéke ismeretlen. Az ismertek: %s.',
            $key,
            $value,
            implode(', ', array_column($enum::cases(), 'value')),
        ));
    }
}
```

> **Miért nem `validateNotEmpty()`:** a Payum `ArrayObject::validateNotEmpty()`
> a `createConfig()` idejében futna, amikor a gateway még csak összeáll. Az
> üres merchantot és titkot a protokoll-csomag `Config` konstruktora utasítja
> el, `ConfigurationException`-nel, a `payum.api` kiértékelésekor — ott, ahol
> a hiba valóban jelentkezik, és a protokoll-csomag saját üzenetével.

- [ ] **Step 4: A Bundle megírása**

`src/Bundle/Builder/SimplePayGatewayFactoryBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Bundle\Builder;

use CodeConjure\SimplePayPayum\SimplePayGatewayFactory;
use Payum\Core\Bridge\Symfony\Builder\GatewayFactoryBuilder;
use Payum\Core\GatewayFactoryInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Ez az EGYETLEN bekötési pont, amit ez a csomag regisztrál.
 *
 * A `sylius.gateway_configuration_type` tag SZÁNDÉKOSAN nincs itt — az a
 * Sylius plugin dolga. Ez a két tag választja szét a két csomagot a
 * Symfony konténerben.
 */
#[AutoconfigureTag(name: 'payum.gateway_factory_builder', attributes: ['factory' => 'simplepay'])]
final class SimplePayGatewayFactoryBuilder extends GatewayFactoryBuilder
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(SimplePayGatewayFactory::class);
    }

    /** @param array<string, mixed> $defaultConfig */
    public function build(array $defaultConfig, GatewayFactoryInterface $coreGatewayFactory): GatewayFactoryInterface
    {
        return new SimplePayGatewayFactory(
            $this->httpClient,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
            $defaultConfig,
            $coreGatewayFactory,
        );
    }
}
```

`src/Bundle/SimplePayPayumBundle.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Bundle;

use CodeConjure\SimplePayPayum\Bundle\DependencyInjection\SimplePayPayumExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class SimplePayPayumBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SimplePayPayumExtension();
    }
}
```

`src/Bundle/DependencyInjection/SimplePayPayumExtension.php`:

```php
<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Bundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class SimplePayPayumExtension extends Extension
{
    /** @param array<array-key, mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'))
            ->load('services.xml');
    }
}
```

`src/Bundle/Resources/config/services.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">
    <services>
        <service id="codeconjure_simplepay_payum.gateway_factory_builder"
                 class="CodeConjure\SimplePayPayum\Bundle\Builder\SimplePayGatewayFactoryBuilder">
            <argument type="service" id="Psr\Http\Client\ClientInterface"/>
            <argument type="service" id="Psr\Http\Message\RequestFactoryInterface"/>
            <argument type="service" id="Psr\Http\Message\StreamFactoryInterface"/>
            <argument type="service" id="logger" on-invalid="null"/>
            <tag name="payum.gateway_factory_builder" factory="simplepay"/>
        </service>
    </services>
</container>
```

> **A tag itt is és az attribútumban is szerepel.** Az `AutoconfigureTag`
> akkor hat, ha az alkalmazás autoconfigure-ral tölti be az osztályt; a
> `services.xml` az explicit út. A kettő együtt nem okoz dupla regisztrációt
> (a Symfony ugyanazt a taget egyszer veszi fel), viszont mindkét
> telepítési módban működik.

- [ ] **Step 5: Futtasd, győződj meg róla, hogy átmegy**

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: a **teljes** suite PASS, phpstan és ECS tiszta.

- [ ] **Step 6: Commit**

```bash
git add src/SimplePayGatewayFactory.php src/Bundle tests/Unit/SimplePayGatewayFactoryTest.php
git commit -m "feat: gateway factory és Symfony bundle

Négy konfigurációs kulcs: merchant, secretKey, environment, currency. A
pénznem azért kell, mert a SimplePay merchant azonosító pénznemhez kötött.
A bundle egyetlen bekötési pontot regisztrál, a payum.gateway_factory_builder
taget — a sylius.gateway_configuration_type a plugin dolga."
```

---

### Task 17: README és a CI lezárása

**Files:**
- Create: `README.md`
- Modify: `.github/workflows/ci.yaml`

**Interfaces:**
- Consumes: minden korábbi task
- Produces: telepíthető, dokumentált csomag; zöld CI

- [ ] **Step 1: A CI javítása — a protokoll-csomag elérhetővé tétele**

A Task 1-ben a `composer.json` `repositories` blokkja egy helyi útvonalra mutat, ami a GitHub runneren nem létezik. Cseréld VCS repository-ra:

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/connorhu/simplepay-lib"
    }
]
```

és a kikötést arra az ágra/tagre, ami valóban létezik:

```json
"require": {
    "codeconjure/simplepay": "dev-main"
}
```

> **Döntés, amit itt meg kell hozni:** ha a `codeconjure/simplepay` addigra
> felkerült a Packagistra, a `repositories` blokk **törölhető**, és a kikötés
> `^1.0` maradhat. Ellenőrizd: `composer show codeconjure/simplepay --all`.
> Ha nincs fent, a VCS repository a helyes megoldás, és a `dev-main` kikötés
> mellé `"minimum-stability": "dev"` + `"prefer-stable": true` kell.
> **Ne hagyd a path repositoryt a commitolt `composer.json`-ban** — az csak
> a te gépeden működik, és a CI némán zöld helyett hangosan piros lesz.

Helyi fejlesztéshez a path repository a `composer.json`-on kívül is megadható:

```bash
composer config repositories.simplepay path ../simplepay
```

Ez a `composer.json`-ba ír, tehát commit előtt vissza kell vonni:

```bash
composer config --unset repositories.simplepay
```

- [ ] **Step 2: A README megírása**

`README.md`:

````markdown
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
`paymentUrl`, `timeout`, `attempt`, `lastCheckedAt`, `ipnLog`.

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
    // aláírt visszaigazolás; a modellt a reply eldobása UTÁN is perzisztálni kell
}
```

**A modell visszaírása a hívó felelőssége.** A `HttpResponse` egy
`ReplyInterface`, amit `throw` visz ki az actionből — a details frissítése
tehát kivétel-úton hagyja el. `finally` ágban (vagy azzal egyenértékűen)
kell perzisztálni, különben egy sikeresen feldolgozott IPN nyomtalan marad.

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
````

- [ ] **Step 3: Ellenőrzés**

```bash
composer validate --strict
vendor/bin/phpunit
vendor/bin/phpstan analyse -c phpstan.dist.neon
vendor/bin/ecs check
```

Elvárt: mind a négy hiba nélkül. A `composer validate` figyelmeztethet a
hiányzó `authors` mezőre — ez nem hiba, opcionálisan kitölthető.

- [ ] **Step 4: Commit és push**

```bash
git add README.md composer.json .github/workflows/ci.yaml
git commit -m "docs: README és a CI függőség-feloldásának lezárása

A README kimondja a details szerződést, az IPN Symfony-híd követelményét,
és hogy a modell visszaírása a HttpResponse reply eldobása után a hívó
felelőssége. A path repository helyett VCS, hogy a CI is fel tudja oldani
a protokoll-csomagot."
git push origin main
```

---

## Önellenőrzés — spec-lefedettség

| Spec fejezet | Task |
|---|---|
| 2. rétegzés szabálya, 2.1 nincs itt Convert | Global Constraints; Task 16 (a tagek szétválasztása) |
| 3. csomag-azonosítók, függőségek | Task 1 |
| 4. fájlstruktúra | Fájlszerkezet táblázat; Task 1–16 |
| 5. `details` szerződés | Task 4, 6, 7 |
| 6. `Api` | Task 8 |
| 7.1 `CaptureAction`, újra-capture őr, `attempt` | Task 11 |
| 7.2 `NotifyAction`, mindig visszaigazolás, perzisztálás | Task 13; README (Task 17) |
| 7.3 `ResolveIpnAction` | Task 12 |
| 7.4 `SyncAction` | Task 14 |
| 7.5 `StatusAction`, hálózat nélkül | Task 9 |
| 7.6 `RefundAction`, nincs alapértelmezett összeg | Task 15 |
| 8. `StatusMap` kimerítő `match` | Task 9 |
| 9. `TransitionGuard` tábla | Task 10 |
| 10. `IpnLog` korlát és `repeatCount` | Task 5, 6 |
| 11. `GetHttpRequestWithHeaders` | Task 12 |
| 12. Bundle, egyetlen tag | Task 16 |
| 13.1 a kliens nem mockolható | Task 11 (`ApiFactory`) |
| 13.2 fixture, két megfigyelés | Task 12 |
| 13.3 kötelező teszteset-csoportok | Task 9–16, mindegyik saját tesztfájllal |
| 14. CI | Task 1, 17 |
| 15. fegyelmi mércék | Global Constraints |
| 16. ismert bizonytalanságok | Task 17 (README) |
| 17. elfogadási kritériumok | lásd lent |
| 18. hatókörön kívül | nincs task — szándékosan |

## Önellenőrzés — elfogadási kritériumok

| Kritérium | Hol bizonyul |
|---|---|
| 1. `Capture` valódi `/start`-ot állít elő és átirányít | `CaptureActionTest::testItStartsATransactionAndRedirectsToTheHostedPage` |
| 2. aláírt IPN 200-as visszaigazolást kap mindhárom kimenetelre | `NotifyActionTest` — `testItAppliesTheStatus…`, `testADuplicateIsStillConfirmed…`, `testADisallowedTransitionIsStillConfirmed…` |
| 3. érvénytelen aláírás és idegen merchant nem kap visszaigazolást | `NotifyActionTest::testAForgedSignatureProducesNoConfirmationAtAll`, `ResolveIpnActionTest::testAForeignMerchantIsRejected` |
| 4. `StatusAction` nem hálózatozik | `StatusActionTest::testItDoesNotDependOnAnApiSoItCannotAccidentallyCallTheNetwork` |
| 5. phpstan level 9 és ECS tisztán | minden task 4. lépése |
| 6. a 13.3 táblázat minden csoportja lefedve | Task 9–16 |
