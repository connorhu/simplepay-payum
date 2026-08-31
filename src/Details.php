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
     * Egy névtér kiolvasása a details tömbből.
     *
     * A `fromModel()` BÁRMILYEN `\ArrayAccess` modellt elfogad, nem csak a Payum
     * `ArrayObject`-jét. Egy hívó tárolhat beágyazott `\ArrayObject`-et névtér-
     * értékként — a felső réteg például sima SPL `\ArrayObject`-et ad modellként
     * olyankor, amikor nincs mihez kötni az értesítést. Ezért csomagoljuk ki,
     * mielőtt döntenénk: enélkül az `is_array()` ellenőrzés elbukna, és egy
     * ténylegesen feltöltött névtér csendben "hiányzó"-ként olvasódna.
     *
     * A Payum `ArrayObject::offsetGet()` maga NEM csomagol — a nyers tárolt
     * értéket adja vissza —, tehát ezen az úton beágyazott ArrayObject csak
     * akkor keletkezik, ha a hívó maga tárolt olyat.
     *
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
