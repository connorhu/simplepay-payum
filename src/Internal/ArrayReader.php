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

        $typed = [];

        foreach ($value as $mapKey => $mapValue) {
            if (!is_string($mapKey)) {
                return null;
            }

            $typed[$mapKey] = $mapValue;
        }

        return $typed;
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

            $typedItem = [];

            foreach ($item as $itemKey => $itemValue) {
                if (is_string($itemKey)) {
                    $typedItem[$itemKey] = $itemValue;
                }
            }

            $list[] = $typedItem;
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
