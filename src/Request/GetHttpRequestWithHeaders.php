<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Request;

use Payum\Core\Request\GetHttpRequest;

/**
 * A Payum `GetHttpRequest` fejlécekkel, deklarált property-ben.
 *
 * A `GetHttpRequest` osztályon `#[\AllowDynamicProperties]` van, és a Symfony-híd
 * (`Payum\Bundle\PayumBundle\Action\GetHttpRequestAction::updateRequest()`)
 * dinamikusan tűzi rá a `headers` értéket — deklarált property nélkül. Ez
 * phpstan level 9 alatt nem definiált property olvasása.
 *
 * A híd `supports()`-a `instanceof GetHttpRequest`-et néz, tehát ezt az
 * alosztályt ugyanaz az action szolgálja ki, és ugyanaz az értékadás tölti
 * a `headers`-t — csak most tipizált, deklarált property-be.
 *
 * ISMERT KORLÁT: a `Payum\Core\Bridge\PlainPhp\Action\GetHttpRequestAction`
 * nem tölti a fejléceket. Ilyen környezetben a `$headers` üres marad, és a
 * `NotifyAction` hangosan elbukik hiányzó `Signature` fejléc hibával.
 * Az IPN feldolgozása Symfony-hidat igényel.
 */
final class GetHttpRequestWithHeaders extends GetHttpRequest
{
    /**
     * Promotált property, hogy a tömb-típus `@param`-mal legyen dokumentálva,
     * ne property-szintű `@var`-ral — a híd ettől függetlenül `->headers =`
     * értékadással tölti fel a példányt konstruktor után is.
     *
     * @param array<string, list<string|null>> $headers
     */
    public function __construct(public array $headers = [])
    {
        parent::__construct();
    }
}
