<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Action;

use CodeConjure\SimplePayPayum\Action\StatusAction;
use CodeConjure\SimplePayPayum\Details;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\Capture;
use Payum\Core\Request\GetHumanStatus;
use PHPUnit\Framework\TestCase;

final class StatusActionTest extends TestCase
{
    public function testItReadsTheStoredStatusWithoutTouchingTheNetwork(): void
    {
        $model = new ArrayObject([
            Details::STATE_KEY => ['transactionId' => 'T1', 'status' => 'FINISHED'],
        ]);
        $request = new GetHumanStatus($model);

        new StatusAction()->execute($request);

        self::assertTrue($request->isCaptured());
    }

    public function testAPaymentThatWasNeverStartedIsNew(): void
    {
        $request = new GetHumanStatus(new ArrayObject([]));

        new StatusAction()->execute($request);

        self::assertTrue($request->isNew());
    }

    public function testItSupportsOnlyStatusRequestsWithAnArrayAccessModel(): void
    {
        $action = new StatusAction();

        self::assertTrue($action->supports(new GetHumanStatus(new ArrayObject([]))));
        self::assertFalse($action->supports(new Capture(new ArrayObject([]))));
        self::assertFalse($action->supports(new GetHumanStatus(new \stdClass())));
    }

    public function testItDoesNotDependOnAnApiSoItCannotAccidentallyCallTheNetwork(): void
    {
        // Reflectionnel vizsgáljuk, ne közvetlen `instanceof`-fal: a
        // statikus elemző a sima `instanceof`-ot a saját forráskódunkból
        // mindig hamisnak ismerné fel, és holt kódnak jelezné — pedig a
        // teszt lényege épp az, hogy ez a hiány futásidőben is ellenőrzött
        // tény maradjon, ne csak a jelenlegi forráskód statikus olvasata.
        $reflection = new \ReflectionClass(StatusAction::class);

        self::assertFalse(
            $reflection->implementsInterface(\Payum\Core\ApiAwareInterface::class),
            'A StatusAction nem kaphat API-t: ha kap, előbb-utóbb hálózatozni fog. '
            . 'A lekérdezés a SyncAction dolga.',
        );
    }

    public function testExecuteRefusesARequestOfTheWrongType(): void
    {
        // Az őrök a futásidejű kikényszerítés; a supports() csak a deklaratív
        // szerződés, amit a Payum kérdez meg. Ha egy hívó megkerüli a
        // supports()-ot, az execute()-nak akkor is el kell utasítania.
        $this->expectException(RequestNotSupportedException::class);

        new StatusAction()->execute(new Capture(new ArrayObject([])));
    }

    public function testExecuteRefusesAStatusRequestWhoseModelIsNotArrayAccess(): void
    {
        $this->expectException(RequestNotSupportedException::class);

        new StatusAction()->execute(new GetHumanStatus(new \stdClass()));
    }
}
