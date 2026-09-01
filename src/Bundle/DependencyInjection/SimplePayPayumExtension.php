<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Bundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class SimplePayPayumExtension extends Extension
{
    /** @param array<array-key, mixed> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'))
            ->load('services.xml');
    }
}
