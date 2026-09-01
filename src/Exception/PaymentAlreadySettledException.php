<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * A `Capture` egy már lezárult fizetésre próbált új tranzakciót indítani.
 *
 * Ez mindig hívói hiba: a `TransactionState::isLive()` csak azt dönti el,
 * van-e MÉG élő, folytatható fizetőoldal — arról nem mond semmit, hogy a
 * fizetés korábban már lezárult-e sikerrel vagy jóváírással. Három állapot
 * után pénz mozgott (`FINISHED`, `REFUND`, `REVERSED`); ezekre indítani egy
 * újabb `/start`-ot dupla terhelést jelentene a vásárló kártyáján, és a friss
 * `TransactionState` felülírná a lezárt tranzakció adatait — a jóváírás
 * ezután bizonyíthatatlan lenne a details-ből. A többi végleges állapot
 * (`CANCELLED`, `TIMEOUT`, `NOTAUTHORIZED`, `FRAUD`) halott kísérlet, arra
 * az újrapróbálkozás jogos, és nem tartozik ide.
 */
final class PaymentAlreadySettledException extends \LogicException implements SimplePayPayumException
{
}
