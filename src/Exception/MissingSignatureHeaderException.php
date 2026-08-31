<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Exception;

/**
 * Az IPN HTTP kérésből hiányzik vagy üres a `Signature` fejléc.
 *
 * Külön kivétel, nem általános „váratlan IPN": aláírás nélkül a törzs
 * hitelesíthetetlen, tehát nincs mit feldolgozni. Alapértelmezés nincs,
 * és aláírás nélküli feldolgozás sincs.
 */
final class MissingSignatureHeaderException extends \RuntimeException implements SimplePayPayumException
{
}
