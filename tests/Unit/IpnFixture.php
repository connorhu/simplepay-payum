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
