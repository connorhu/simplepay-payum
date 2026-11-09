<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Bundle;

use CodeConjure\SimplePayPayum\Bundle\Builder\SimplePayGatewayFactoryBuilder;
use CodeConjure\SimplePayPayum\Bundle\DependencyInjection\SimplePayPayumExtension;
use CodeConjure\SimplePayPayum\SimplePayGatewayFactory;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Payum\Core\GatewayFactoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * A `services.php` a `logger` argumentumot `nullOnInvalid()`-dal adja be —
 * tehát a builder konstruktorának `null`-t is el KELL fogadnia. Ez a
 * névtér korábban egyetlen tesztet sem tartalmazott, pedig ez az egyetlen
 * integrációs varrat, amit ez a csomag birtokol.
 */
final class SimplePayGatewayFactoryBuilderTest extends TestCase
{
    private function builder(?LoggerInterface $logger): SimplePayGatewayFactoryBuilder
    {
        $psr17 = new Psr17Factory();

        return new SimplePayGatewayFactoryBuilder(new MockHttpClient(), $psr17, $psr17, $logger);
    }

    public function testItConstructsWithANullLoggerAndBuildsAFactory(): void
    {
        $builder = $this->builder(null);
        $coreGatewayFactory = $this->createStub(GatewayFactoryInterface::class);

        $factory = $builder->build([], $coreGatewayFactory);

        self::assertInstanceOf(SimplePayGatewayFactory::class, $factory);
    }

    public function testItConstructsWithARealLoggerToo(): void
    {
        $builder = $this->builder(new NullLogger());
        $coreGatewayFactory = $this->createStub(GatewayFactoryInterface::class);

        $factory = $builder->build([], $coreGatewayFactory);

        self::assertInstanceOf(SimplePayGatewayFactory::class, $factory);
    }

    /**
     * A `defaultConfig` a GatewayFactory `defaults()`-án keresztül ér célba:
     * a `createConfig()` ELŐSZÖR ezt írja be, és a `populateConfig()` saját
     * `payum.factory_title` alapértéke csak akkor íródna, ha a kulcs még
     * `null` lenne — tehát ha a `build()` valóban továbbadja a
     * `defaultConfig`-ot, az egyéni cím marad érvényben.
     */
    public function testBuildPassesTheDefaultConfigThrough(): void
    {
        $builder = $this->builder(null);
        $coreGatewayFactory = $this->createStub(GatewayFactoryInterface::class);
        $coreGatewayFactory->method('createConfig')->willReturn([]);

        $factory = $builder->build(['payum.factory_title' => 'Egyéni cím'], $coreGatewayFactory);

        $config = $factory->createConfig([]);

        self::assertSame('Egyéni cím', $config['payum.factory_title']);
    }

    /**
     * A `services.php`-t (a bundle extensionjén át) VALÓDI Symfony konténerbe töltjük be — PSR-18/PSR-17
     * szolgáltatásokkal, de SZÁNDÉKOSAN `logger` nélkül, ahogy egy
     * MonologBundle nélküli alkalmazásban történne. Ha a builder konstruktora
     * valaha visszakerülne nem-nullable `LoggerInterface`-re, ez a teszt a
     * `ContainerBuilder::get()` hívásnál `TypeError`-ral bukna, pontosan úgy,
     * ahogy egy éles alkalmazás az első fizetési kísérletnél bukna.
     */
    public function testTheServiceConfigBootsWithoutALoggerServiceRegistered(): void
    {
        $container = new ContainerBuilder();

        $container->register('http_client', MockHttpClient::class);
        $container->setAlias(ClientInterface::class, 'http_client')->setPublic(true);

        $container->register('psr17_factory', Psr17Factory::class);
        $container->setAlias(RequestFactoryInterface::class, 'psr17_factory')->setPublic(true);
        $container->setAlias(StreamFactoryInterface::class, 'psr17_factory')->setPublic(true);

        // SZÁNDÉKOSAN nincs 'logger' szolgáltatás regisztrálva.

        new SimplePayPayumExtension()->load([], $container);

        $container->getDefinition('codeconjure_simplepay_payum.gateway_factory_builder')->setPublic(true);

        $container->compile();

        $builder = $container->get('codeconjure_simplepay_payum.gateway_factory_builder');

        self::assertInstanceOf(SimplePayGatewayFactoryBuilder::class, $builder);
    }

    /**
     * A betöltött definíció pontosan azt adja, amit a korábbi `services.xml`:
     * PSR-18/PSR-17 referenciák, `null`-ra eső `logger` és a
     * `payum.gateway_factory_builder` tag `factory: simplepay` attribútummal.
     */
    public function testTheServiceDefinitionMatchesTheContract(): void
    {
        $container = new ContainerBuilder();
        new SimplePayPayumExtension()->load([], $container);

        $definition = $container->getDefinition('codeconjure_simplepay_payum.gateway_factory_builder');

        self::assertSame(SimplePayGatewayFactoryBuilder::class, $definition->getClass());
        self::assertFalse($definition->isPublic());

        $arguments = $definition->getArguments();
        self::assertCount(4, $arguments);

        $expected = [
            [ClientInterface::class, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE],
            [RequestFactoryInterface::class, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE],
            [StreamFactoryInterface::class, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE],
            ['logger', ContainerInterface::NULL_ON_INVALID_REFERENCE],
        ];
        foreach ($expected as $index => [$id, $invalidBehavior]) {
            $argument = $arguments[$index];
            self::assertInstanceOf(Reference::class, $argument);
            self::assertSame($id, (string) $argument);
            self::assertSame($invalidBehavior, $argument->getInvalidBehavior());
        }

        self::assertSame([['factory' => 'simplepay']], $definition->getTag('payum.gateway_factory_builder'));
    }
}
