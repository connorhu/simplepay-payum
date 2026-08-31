<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Exception;

use CodeConjure\SimplePayPayum\Exception\InvalidGatewayConfigException;
use CodeConjure\SimplePayPayum\Exception\MissingDetailsException;
use CodeConjure\SimplePayPayum\Exception\MissingSignatureHeaderException;
use CodeConjure\SimplePayPayum\Exception\SimplePayPayumException;
use CodeConjure\SimplePayPayum\Exception\TransactionNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    /** @return iterable<string, array{class-string<\Throwable>, class-string<\Throwable>}> */
    public static function exceptions(): iterable
    {
        yield 'hiányzó details' => [MissingDetailsException::class, \LogicException::class];
        yield 'hibás gateway config' => [InvalidGatewayConfigException::class, \LogicException::class];
        yield 'hiányzó aláírás-fejléc' => [MissingSignatureHeaderException::class, \RuntimeException::class];
        yield 'nem talált tranzakció' => [TransactionNotFoundException::class, \RuntimeException::class];
    }

    /**
     * @param class-string<\Throwable> $exceptionClass
     * @param class-string<\Throwable> $parentClass
     */
    #[DataProvider('exceptions')]
    public function testEveryExceptionIsCatchableThroughTheInterfaceAndItsParent(
        string $exceptionClass,
        string $parentClass,
    ): void {
        $exception = new $exceptionClass('teszt');

        self::assertInstanceOf(SimplePayPayumException::class, $exception);
        self::assertInstanceOf($parentClass, $exception);
    }

    public function testTheInterfaceExtendsThrowableSoASingleCatchWorks(): void
    {
        self::assertTrue(is_a(SimplePayPayumException::class, \Throwable::class, true));
    }
}
