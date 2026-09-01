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
