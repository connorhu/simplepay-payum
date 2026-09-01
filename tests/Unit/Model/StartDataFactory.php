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
