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
        return null !== $this->transactionId &&
            null !== $this->paymentUrl &&
            null !== $this->status &&
            !$this->status->isFinal() &&
            null !== $this->timeout &&
            $this->timeout > $now;
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

        return $this->with(ipnLog: array_slice($log, -self::IPN_LOG_LIMIT));
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
