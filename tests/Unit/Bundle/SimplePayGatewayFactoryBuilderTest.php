<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Tests\Unit\Bundle;

use CodeConjure\SimplePayPayum\Bundle\Builder\SimplePayGatewayFactoryBuilder;
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
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

/**
 * A `services.xml` a `logger` argumentumot `on-invalid="null"`-lal adja be —
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
     * A `services.xml`-t VALÓDI Symfony konténerbe töltjük be — PSR-18/PSR-17
     * szolgáltatásokkal, de SZÁNDÉKOSAN `logger` nélkül, ahogy egy
     * MonologBundle nélküli alkalmazásban történne. Ha a builder konstruktora
     * valaha visszakerülne nem-nullable `LoggerInterface`-re, ez a teszt a
     * `ContainerBuilder::get()` hívásnál `TypeError`-ral bukna, pontosan úgy,
     * ahogy egy éles alkalmazás az első fizetési kísérletnél bukna.
     */
    public function testTheServicesXmlBootsWithoutALoggerServiceRegistered(): void
    {
        $container = new ContainerBuilder();

        $container->register('http_client', MockHttpClient::class);
        $container->setAlias(ClientInterface::class, 'http_client')->setPublic(true);

        $container->register('psr17_factory', Psr17Factory::class);
        $container->setAlias(RequestFactoryInterface::class, 'psr17_factory')->setPublic(true);
        $container->setAlias(StreamFactoryInterface::class, 'psr17_factory')->setPublic(true);

        // SZÁNDÉKOSAN nincs 'logger' szolgáltatás regisztrálva.

        $loader = new XmlFileLoader(
            $container,
            new FileLocator(__DIR__ . '/../../../src/Bundle/Resources/config'),
        );
        $loader->load('services.xml');

        $container->getDefinition('codeconjure_simplepay_payum.gateway_factory_builder')->setPublic(true);

        $container->compile();

        $builder = $container->get('codeconjure_simplepay_payum.gateway_factory_builder');

        self::assertInstanceOf(SimplePayGatewayFactoryBuilder::class, $builder);
    }
}
