<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Model;

use CodeConjure\SimplePay\Currency;
use CodeConjure\SimplePay\Language;
use CodeConjure\SimplePay\PaymentMethod;
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
        $invoice = $input['invoice'];
        self::assertIsArray($invoice);
        $invoice['phone'] = '+36301234567';
        $input['invoice'] = $invoice;

        $data = StartData::fromArray($input);

        self::assertSame('+36301234567', $data->invoice->phone);

        $roundTrippedInvoice = $data->toArray()['invoice'];
        self::assertIsArray($roundTrippedInvoice);
        self::assertSame('+36301234567', $roundTrippedInvoice['phone']);
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
