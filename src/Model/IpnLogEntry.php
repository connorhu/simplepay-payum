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
