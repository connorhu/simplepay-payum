<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Bundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class SimplePayBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
