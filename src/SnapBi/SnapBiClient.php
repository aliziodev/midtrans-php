<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\SnapBi;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Http\CurlTransport;
use Aliziodev\MidtransPhp\Http\Transport;

final class SnapBiClient
{
    public function __construct(
        private readonly MidtransConfig $config,
        private readonly Transport $transport = new CurlTransport,
    ) {}

    /** @return array<string, mixed> */
    public function getAccessToken(): array
    {
        $this->assertSnapBiCredentials();

        $timestamp = gmdate('c');
        $signature = $this->createAsymmetricSignature(
            data: (string) $this->config->snapBiClientId.'|'.$timestamp,
            privateKey: (string) $this->config->snapBiPrivateKey,
        );

        return $this->request(
            method: 'POST',
            path: SnapBiPath::ACCESS_TOKEN,
            headers: [
                'X-CLIENT-KEY' => (string) $this->config->snapBiClientId,
                'X-TIMESTAMP' => $timestamp,
                'X-SIGNATURE' => $signature,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            payload: [
                'grant_type' => 'client_credentials',
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    public function createDirectDebit(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::DEBIT_CREATE, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function createVa(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::VA_CREATE, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function createQris(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::QRIS_CREATE, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function directDebitStatus(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::DEBIT_STATUS, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function vaStatus(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::VA_STATUS, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function qrisStatus(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::QRIS_STATUS, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function directDebitCancel(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::DEBIT_CANCEL, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function vaCancel(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::VA_CANCEL, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function qrisCancel(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::QRIS_CANCEL, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function directDebitRefund(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::DEBIT_REFUND, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function qrisRefund(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::QRIS_REFUND, $payload, $externalId, $accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function authorizedRequest(
        string $method,
        string $path,
        array $payload,
        string $externalId,
        ?string $accessToken,
    ): array {
        $this->assertSnapBiCredentials();

        $token = $accessToken ?? (string) ($this->getAccessToken()['accessToken'] ?? '');
        if ($token === '') {
            throw new MidtransException('Unable to resolve Snap-BI access token.');
        }

        $timestamp = gmdate('c');
        $signature = $this->createSymmetricSignature(
            accessToken: $token,
            requestBody: $payload,
            method: $method,
            path: $path,
            clientSecret: (string) $this->config->snapBiClientSecret,
            timestamp: $timestamp,
        );

        return $this->request(
            method: $method,
            path: $path,
            headers: [
                'Authorization' => 'Bearer '.$token,
                'X-PARTNER-ID' => (string) $this->config->snapBiPartnerId,
                'X-EXTERNAL-ID' => $externalId,
                'X-DEVICE-ID' => (string) ($this->config->snapBiDeviceId ?? 'midtrans-php-sdk'),
                'CHANNEL-ID' => $this->config->snapBiChannelId,
                'X-TIMESTAMP' => $timestamp,
                'X-SIGNATURE' => $signature,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            payload: $payload,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $headers, ?array $payload = null): array
    {
        $jsonBody = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload !== null && $jsonBody === false) {
            throw MidtransException::invalidResponse('Unable to encode payload to JSON.');
        }

        $response = $this->transport->request(
            method: $method,
            url: $this->config->snapBiBaseUrl().$path,
            headers: $headers,
            jsonBody: $jsonBody,
            timeoutSeconds: $this->config->timeoutSeconds,
            maxRetries: $this->config->maxRetries,
            retryDelayMs: $this->config->retryDelayMs,
        );

        $decoded = json_decode($response->body, true);
        if (! is_array($decoded)) {
            throw MidtransException::invalidResponse($response->body);
        }

        if ($response->statusCode >= 400) {
            $message = (string) ($decoded['responseMessage'] ?? $decoded['status_message'] ?? 'Snap-BI API request failed.');
            throw new MidtransApiException($response->statusCode, $decoded, $message);
        }

        return $decoded;
    }

    private function assertSnapBiCredentials(): void
    {
        if ($this->config->snapBiClientId === null || $this->config->snapBiClientId === '') {
            throw new MidtransException('Missing Snap-BI client ID in configuration.');
        }

        if ($this->config->snapBiPrivateKey === null || $this->config->snapBiPrivateKey === '') {
            throw new MidtransException('Missing Snap-BI private key in configuration.');
        }

        if ($this->config->snapBiClientSecret === null || $this->config->snapBiClientSecret === '') {
            throw new MidtransException('Missing Snap-BI client secret in configuration.');
        }

        if ($this->config->snapBiPartnerId === null || $this->config->snapBiPartnerId === '') {
            throw new MidtransException('Missing Snap-BI partner ID in configuration.');
        }
    }

    private function createAsymmetricSignature(string $data, string $privateKey): string
    {
        $signature = '';
        $result = openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if ($result !== true) {
            throw new MidtransException('Unable to generate Snap-BI asymmetric signature.');
        }

        return base64_encode($signature);
    }

    /** @param array<string, mixed> $requestBody */
    private function createSymmetricSignature(
        string $accessToken,
        array $requestBody,
        string $method,
        string $path,
        string $clientSecret,
        string $timestamp,
    ): string {
        $body = json_encode($requestBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw MidtransException::invalidResponse('Unable to encode Snap-BI body to JSON.');
        }

        $hashedBody = strtolower(bin2hex(hash('sha256', $body, true)));
        $payload = strtoupper($method).':'.$path.':'.$accessToken.':'.$hashedBody.':'.$timestamp;

        return base64_encode(hash_hmac('sha512', $payload, $clientSecret, true));
    }
}
