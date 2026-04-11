<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum\Action;

use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Model\PaymentInterface;
use Payum\Core\Request\Convert;

class ConvertPaymentAction implements ActionInterface
{
    public function execute($request): void
    {
        /** @var Convert $request */
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getSource();
        $details = ArrayObject::ensureArrayObject($payment->getDetails());

        $details['orderRef']      = $payment->getNumber();
        $details['currency']      = $payment->getCurrencyCode();
        $details['total']         = $payment->getTotalAmount();
        $details['customerEmail'] = $payment->getClientEmail();
        $details['language']      = $details['language'] ?? 'HU';
        $details['methods']       = $details['methods'] ?? ['CARD'];
        $details['timeout']       = $details['timeout'] ?? (new \DateTimeImmutable('+30 minutes'))->format(\DateTimeInterface::ATOM);

        $request->setResult((array) $details);
    }

    public function supports($request): bool
    {
        return $request instanceof Convert
            && $request->getSource() instanceof PaymentInterface
            && $request->getTo() === 'array';
    }
}
