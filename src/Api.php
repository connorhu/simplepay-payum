<?php

declare(strict_types=1);

namespace CodeConjure\SimplePayPayum;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Api
{
    private const SANDBOX_URL    = 'https://sandbox.simplepay.hu/payment/v2/';
    private const PRODUCTION_URL = 'https://simplepay.otpbank.hu/payment/v2/';

    public function __construct(
        private readonly string $merchantId,
        private readonly string $secretKey,
        private readonly bool $sandbox,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    public function getMerchantId(): string
    {
        return $this->merchantId;
    }

    public function startPayment(array $data): array
    {
        return $this->post('start', $data);
    }

    public function queryPayment(array $data): array
    {
        return $this->post('query', $data);
    }

    public function refundPayment(array $data): array
    {
        return $this->post('refund', $data);
    }

    public function verifySignature(string $body, string $signature): bool
    {
        return hash_equals($this->sign($body), $signature);
    }

    public function sign(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, $this->secretKey, true));
    }

    private function post(string $endpoint, array $data): array
    {
        $body    = json_encode($data, JSON_THROW_ON_ERROR);
        $request = $this->requestFactory
            ->createRequest('POST', $this->getBaseUrl() . $endpoint)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Signature', $this->sign($body))
            ->withBody($this->streamFactory->createStream($body));

        $response = $this->httpClient->sendRequest($request);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf(
                'SimplePay API error %d: %s',
                $response->getStatusCode(),
                $response->getReasonPhrase()
            ));
        }

        $responseBody = (string) $response->getBody();
        if ($responseBody === '' || $responseBody === '{}') {
            return [];
        }

        return json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    }

    private function getBaseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }
}
