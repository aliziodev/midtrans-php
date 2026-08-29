<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\SnapBi;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Http\CurlTransport;
use Aliziodev\MidtransPhp\Http\Transport;
use Aliziodev\MidtransPhp\Support\Sdk;

final class SnapBiClient
{
    /**
     * Renew slightly before the real expiry so a token cannot lapse mid-flight.
     */
    private const TOKEN_EXPIRY_MARGIN_SECONDS = 60;

    private ?string $cachedAccessToken = null;

    private int $cachedAccessTokenExpiresAt = 0;

    public function __construct(
        private readonly MidtransConfig $config,
        private readonly Transport $transport = new CurlTransport,
    ) {}

    /**
     * Drops the cached B2B access token, forcing the next call to mint a new one.
     */
    public function clearAccessTokenCache(): void
    {
        $this->cachedAccessToken = null;
        $this->cachedAccessTokenExpiresAt = 0;
    }

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
                'User-Agent' => Sdk::userAgent(),
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
    public function getDirectDebitStatus(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::DEBIT_STATUS, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function getVaStatus(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::VA_STATUS, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function getQrisStatus(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::QRIS_STATUS, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function cancelDirectDebit(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::DEBIT_CANCEL, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function cancelVa(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::VA_CANCEL, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function cancelQris(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::QRIS_CANCEL, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function refundDirectDebit(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::DEBIT_REFUND, $payload, $externalId, $accessToken);
    }

    /** @param array<string, mixed> $payload */
    public function refundQris(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::QRIS_REFUND, $payload, $externalId, $accessToken);
    }

    /**
     * Link a customer account (GoPay Tokenization).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/binding-api
     */
    public function bindAccount(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::ACCOUNT_BINDING, $payload, $externalId, $accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/unbind-api
     */
    public function unbindAccount(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::ACCOUNT_UNBINDING, $payload, $externalId, $accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/binding-inquiry-api
     */
    public function getAccountBindingStatus(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::ACCOUNT_INQUIRY, $payload, $externalId, $accessToken);
    }

    /**
     * Capture a previously authorised pre-auth transaction.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/auth-payment-api-gopay-tokenization
     */
    public function captureAuthorization(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::AUTH_CAPTURE, $payload, $externalId, $accessToken);
    }

    /**
     * Release a pre-auth hold without capturing it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/cancel-api
     */
    public function voidAuthorization(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::AUTH_VOID, $payload, $externalId, $accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/transaction-history-list-api
     */
    public function getTransactionHistoryList(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::TRANSACTION_HISTORY_LIST, $payload, $externalId, $accessToken);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/transaction-history-detail-api
     */
    public function getTransactionHistoryDetail(array $payload, string $externalId, ?string $accessToken = null): array
    {
        return $this->authorizedRequest('POST', SnapBiPath::TRANSACTION_HISTORY_DETAIL, $payload, $externalId, $accessToken);
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
        ExternalId::assertValid($externalId);

        $token = $accessToken ?? $this->resolveAccessToken();

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
                'User-Agent' => Sdk::userAgent(),
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
            throw new MidtransException('Unable to encode Snap-BI payload to JSON: '.json_last_error_msg());
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

        /** @var array<string, mixed> $decoded */
        if ($response->statusCode >= 400) {
            $message = (string) ($decoded['responseMessage'] ?? $decoded['status_message'] ?? 'Snap-BI API request failed.');
            throw new MidtransApiException($response->statusCode, $decoded, $message);
        }

        // Snap-BI reports the real outcome in responseCode; the leading digits
        // repeat the HTTP status, so a 2xx body can still carry a 4xx/5xx result.
        $responseCode = (string) ($decoded['responseCode'] ?? '');

        if (strlen($responseCode) === 7 && ! str_starts_with($responseCode, '2')) {
            throw new MidtransApiException(
                statusCode: (int) substr($responseCode, 0, 3),
                payload: $decoded,
                message: (string) ($decoded['responseMessage'] ?? 'Snap-BI API returned an error responseCode.'),
            );
        }

        return $decoded;
    }

    /**
     * Reuses the B2B token for its advertised lifetime instead of minting one per
     * request, which doubled the request count and burned rate limit.
     */
    private function resolveAccessToken(): string
    {
        if ($this->cachedAccessToken !== null && time() < $this->cachedAccessTokenExpiresAt) {
            return $this->cachedAccessToken;
        }

        $response = $this->getAccessToken();
        $token = (string) ($response['accessToken'] ?? '');

        if ($token === '') {
            throw new MidtransException('Unable to resolve Snap-BI access token: response carried no accessToken.');
        }

        $expiresIn = (int) ($response['expiresIn'] ?? 0);

        $this->cachedAccessToken = $token;
        $this->cachedAccessTokenExpiresAt = $expiresIn > self::TOKEN_EXPIRY_MARGIN_SECONDS
            ? time() + $expiresIn - self::TOKEN_EXPIRY_MARGIN_SECONDS
            : 0;

        return $token;
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
        // Parsed first so a malformed PEM produces an actionable message instead
        // of openssl_sign() emitting a raw PHP warning on its way to failing.
        $key = openssl_pkey_get_private($privateKey);

        if ($key === false) {
            throw new MidtransException(
                'Snap-BI private key could not be read. Pass the PEM contents themselves, '
                .'including the -----BEGIN PRIVATE KEY----- header, not a path to the file.'
            );
        }

        $signature = '';

        if (openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256) !== true) {
            throw new MidtransException('Unable to generate Snap-BI asymmetric signature.'); // @codeCoverageIgnore
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
            throw new MidtransException('Unable to encode Snap-BI body to JSON: '.json_last_error_msg());
        }

        $hashedBody = strtolower(bin2hex(hash('sha256', $body, true)));
        $payload = strtoupper($method).':'.$path.':'.$accessToken.':'.$hashedBody.':'.$timestamp;

        return base64_encode(hash_hmac('sha512', $payload, $clientSecret, true));
    }
}
