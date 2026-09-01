<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * A Payum gateway konfigurációja hiányos vagy értelmezhetetlen — például
 * ismeretlen `environment` vagy `currency` érték.
 */
final class InvalidGatewayConfigException extends \LogicException implements SimplePayPayumException
{
}
