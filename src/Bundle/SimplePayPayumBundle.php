<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Bundle;

use CodeConjure\SimplePayPayum\Bundle\DependencyInjection\SimplePayPayumExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class SimplePayPayumBundle extends Bundle
{
    public function getContainerExtension(): ExtensionInterface
    {
        return new SimplePayPayumExtension();
    }
}
