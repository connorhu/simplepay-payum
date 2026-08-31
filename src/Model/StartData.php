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
