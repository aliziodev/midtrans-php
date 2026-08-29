<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Exceptions\MidtransPendingException;
use Aliziodev\MidtransPhp\Http\CurlTransport;
use Aliziodev\MidtransPhp\Http\Transport;
use Aliziodev\MidtransPhp\Support\IdempotencyKey;
use Aliziodev\MidtransPhp\Support\Sdk;

final class MidtransClient
{
    /**
     * Paths where Midtrans documents that Idempotency-Key is not accepted.
     */
    private const IDEMPOTENCY_UNSUPPORTED_PATHS = [
        '/v2/token',
        '/v2/card/register',
        '/v2/pay/account',
    ];

    public function __construct(
        private readonly MidtransConfig $config,
        private readonly Transport $transport = new CurlTransport,
        private readonly ?string $idempotencyKey = null,
    ) {}

    public function withIdempotencyKey(string $idempotencyKey): self
    {
        return new self(
            config: $this->config,
            transport: $this->transport,
            idempotencyKey: $idempotencyKey,
        );
    }

    /** @param array<string, mixed> $payload */
    public function snapCreateTransaction(array $payload): array
    {
        return $this->request('POST', $this->config->snapBaseUrl().'/transactions', $payload);
    }

    /** @param array<string, mixed> $payload */
    public function coreCharge(array $payload): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v2/charge', $payload);
    }

    /** @param array<string, mixed> $payload */
    public function captureTransaction(array $payload): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v2/capture', $payload);
    }

    public function transactionStatusB2b(string $orderOrTransactionId): array
    {
        return $this->request('GET', $this->config->coreBaseUrl().'/v2/'.rawurlencode($orderOrTransactionId).'/status/b2b');
    }

    public function transactionStatus(string $orderOrTransactionId): array
    {
        return $this->request('GET', $this->config->coreBaseUrl().'/v2/'.rawurlencode($orderOrTransactionId).'/status');
    }

    public function approveTransaction(string $orderOrTransactionId): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v2/'.rawurlencode($orderOrTransactionId).'/approve');
    }

    public function denyTransaction(string $orderOrTransactionId): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v2/'.rawurlencode($orderOrTransactionId).'/deny');
    }

    public function cancelTransaction(string $orderOrTransactionId): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v2/'.rawurlencode($orderOrTransactionId).'/cancel');
    }

    public function expireTransaction(string $orderOrTransactionId): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v2/'.rawurlencode($orderOrTransactionId).'/expire');
    }

    /** @param array<string, mixed> $payload */
    public function refundTransaction(string $orderOrTransactionId, array $payload): array
    {
        $this->assertRefundKeyPresent($payload);

        return $this->request('POST', $this->config->coreBaseUrl().'/v2/'.rawurlencode($orderOrTransactionId).'/refund', $payload);
    }

    /** @param array<string, mixed> $payload */
    public function refundTransactionDirect(string $orderOrTransactionId, array $payload): array
    {
        $this->assertRefundKeyPresent($payload);

        return $this->request('POST', $this->config->coreBaseUrl().'/v2/'.rawurlencode($orderOrTransactionId).'/refund/online/direct', $payload);
    }

    /** @param array<string, mixed> $payload */
    public function linkPaymentAccount(array $payload): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v2/pay/account', $payload);
    }

    public function getPaymentAccount(string $accountId): array
    {
        return $this->request('GET', $this->config->coreBaseUrl().'/v2/pay/account/'.rawurlencode($accountId));
    }

    public function unlinkPaymentAccount(string $accountId): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v2/pay/account/'.rawurlencode($accountId).'/unbind');
    }

    /** @param array<string, mixed> $payload */
    public function createSubscription(array $payload): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v1/subscriptions', $payload);
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', $this->config->coreBaseUrl().'/v1/subscriptions/'.rawurlencode($subscriptionId));
    }

    /** @param array<string, mixed> $payload */
    public function updateSubscription(string $subscriptionId, array $payload): array
    {
        return $this->request('PATCH', $this->config->coreBaseUrl().'/v1/subscriptions/'.rawurlencode($subscriptionId), $payload);
    }

    public function disableSubscription(string $subscriptionId): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v1/subscriptions/'.rawurlencode($subscriptionId).'/disable');
    }

    public function enableSubscription(string $subscriptionId): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v1/subscriptions/'.rawurlencode($subscriptionId).'/enable');
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v1/subscriptions/'.rawurlencode($subscriptionId).'/cancel');
    }

    public function getSnapToken(array $payload): string
    {
        return (string) ($this->snapCreateTransaction($payload)['token'] ?? '');
    }

    public function getSnapUrl(array $payload): string
    {
        return (string) ($this->snapCreateTransaction($payload)['redirect_url'] ?? '');
    }

    /**
     * @deprecated since 2.0.0, to be removed in 3.0.0.
     *
     * PCI-DSS warning: this puts the full PAN in a URL query string and makes
     * your server handle raw card data, which pulls it into PCI-DSS SAQ D scope.
     * URLs are logged by web servers, proxies and APM agents.
     *
     * Midtrans documents tokenization as a browser-side flow: load
     * midtrans-new-3ds.min.js with your client key and call
     * MidtransNew3ds.getCardToken(), then send only the resulting token_id to
     * your backend.
     *
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/get-token
     */
    public function cardRegister(string $cardNumber, string $expMonth, string $expYear): array
    {
        $this->assertClientKeyPresent();

        $query = http_build_query([
            'card_number' => $cardNumber,
            'card_exp_month' => $expMonth,
            'card_exp_year' => $expYear,
            'client_key' => $this->config->clientKey,
        ]);

        return $this->request('GET', $this->config->coreBaseUrl().'/v2/card/register?'.$query);
    }

    /**
     * @deprecated since 2.0.0, to be removed in 3.0.0.
     *
     * PCI-DSS warning: this puts the full PAN and CVV in a URL query string and makes
     * your server handle raw card data, which pulls it into PCI-DSS SAQ D scope.
     * URLs are logged by web servers, proxies and APM agents.
     *
     * Midtrans documents tokenization as a browser-side flow: load
     * midtrans-new-3ds.min.js with your client key and call
     * MidtransNew3ds.getCardToken(), then send only the resulting token_id to
     * your backend.
     *
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/get-token
     */
    public function cardToken(string $cardNumber, string $expMonth, string $expYear, string $cvv): array
    {
        $this->assertClientKeyPresent();

        $query = http_build_query([
            'card_number' => $cardNumber,
            'card_exp_month' => $expMonth,
            'card_exp_year' => $expYear,
            'card_cvv' => $cvv,
            'client_key' => $this->config->clientKey,
        ]);

        return $this->request('GET', $this->config->coreBaseUrl().'/v2/token?'.$query);
    }

    public function cardPointInquiry(string $tokenId): array
    {
        return $this->request('GET', $this->config->coreBaseUrl().'/v2/point_inquiry/'.rawurlencode($tokenId));
    }

    /** @param array<string, mixed> $payload */
    public function createPaymentLink(array $payload): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v1/payment-links', $payload);
    }

    public function getPaymentLinkDetails(string $orderId): array
    {
        return $this->request('GET', $this->config->coreBaseUrl().'/v1/payment-links/'.rawurlencode($orderId));
    }

    public function deletePaymentLink(string $orderId): array
    {
        return $this->request('DELETE', $this->config->coreBaseUrl().'/v1/payment-links/'.rawurlencode($orderId));
    }

    public function getBalanceMutation(string $currency, string $startTime, string $endTime): array
    {
        $query = http_build_query([
            'currency' => $currency,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        return $this->request('GET', $this->config->coreBaseUrl().'/v1/balance/mutation?'.$query);
    }

    /** @param array<string, mixed> $payload */
    public function createInvoice(array $payload): array
    {
        return $this->request('POST', $this->config->coreBaseUrl().'/v1/invoices', $payload);
    }

    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', $this->config->coreBaseUrl().'/v1/invoices/'.rawurlencode($invoiceId));
    }

    public function voidInvoice(string $invoiceId): array
    {
        return $this->request('PATCH', $this->config->coreBaseUrl().'/v1/invoices/'.rawurlencode($invoiceId).'/void');
    }

    /**
     * Obtain card properties from the Bank Identification Number.
     *
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/bin-api
     */
    public function getBin(string $binNumber): array
    {
        return $this->request('GET', $this->config->coreBaseUrl().'/v1/bins/'.rawurlencode($binNumber));
    }

    /**
     * Convert a quotation document into an invoice.
     *
     * Only documents with document_type = quotation qualify, and only while the
     * quotation is unexpired and not already converted.
     *
     * @param  array<string, mixed>  $payload  optional client overrides
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/convert-invoice
     */
    public function convertInvoice(string $invoiceId, array $payload = []): array
    {
        return $this->request(
            'PATCH',
            $this->config->coreBaseUrl().'/v1/invoices/'.rawurlencode($invoiceId).'/convert',
            $payload === [] ? null : $payload,
        );
    }

    /**
     * Cancel a Snap page before its expiry time.
     *
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/cancel-a-snap-session
     */
    public function cancelSnapSession(string $snapToken): array
    {
        return $this->request('POST', $this->config->snapBaseUrl().'/transactions/'.rawurlencode($snapToken).'/cancel');
    }

    /**
     * Read the Snap Checkout look and feel plus the active payment methods.
     *
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/snap-checkout-preference-api
     */
    public function getSnapPreferences(): array
    {
        return $this->request('GET', $this->config->snapV3BaseUrl().'/merchant-preferences');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateSnapPreferences(array $payload): array
    {
        return $this->request('PATCH', $this->config->snapV3BaseUrl().'/merchant-preferences', $payload);
    }

    /**
     * Promotions available for a GoPay-linked account.
     *
     * Pass $accountId = null for the account-agnostic listing.
     *
     * @return array<string, mixed>
     *
     * @see https://docs.midtrans.com/reference/fetch-promotion-gopay-tokenization
     */
    public function getGopayPromotions(?string $accountId, int|string $grossAmount, string $currency = 'IDR'): array
    {
        $query = http_build_query([
            'gross_amount' => (string) $grossAmount,
            'currency' => $currency,
        ]);

        $path = $accountId === null || $accountId === ''
            ? '/v2/gopay/promo/'
            : '/v2/gopay/promo/'.rawurlencode($accountId);

        return $this->request('GET', $this->config->coreBaseUrl().$path.'?'.$query);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $payload = null): array
    {
        $method = strtoupper($method);

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic '.base64_encode($this->config->serverKey.':'),
            'User-Agent' => Sdk::userAgent(),
        ];

        if ($this->config->appendNotificationUrl !== null) {
            $headers['X-Append-Notification'] = $this->config->appendNotificationUrl;
        }

        if ($this->config->overrideNotificationUrl !== null) {
            $headers['X-Override-Notification'] = $this->config->overrideNotificationUrl;
        }

        if ($this->config->paymentLocale !== null) {
            $headers['X-Payment-Locale'] = $this->config->paymentLocale;
        }

        if ($this->config->popId !== null) {
            $headers['X-POP-ID'] = $this->config->popId;
        }

        $idempotencyKey = $this->resolveIdempotencyKey($method, $url);

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $jsonBody = $payload === null ? null : json_encode($payload);

        if ($payload !== null && $jsonBody === false) {
            throw new MidtransException('Unable to encode payload to JSON: '.json_last_error_msg());
        }

        $response = $this->transport->request(
            method: $method,
            url: $url,
            headers: $headers,
            jsonBody: $jsonBody,
            timeoutSeconds: $this->config->timeoutSeconds,
            maxRetries: $this->maxRetriesFor($method, $url),
            retryDelayMs: $this->config->retryDelayMs,
        );

        $decoded = json_decode($response->body, true);

        if (! is_array($decoded)) {
            throw MidtransException::invalidResponse($response->body);
        }

        /** @var array<string, mixed> $decoded */
        if ($response->statusCode === 202) {
            throw new MidtransPendingException($response->statusCode, $decoded, $idempotencyKey);
        }

        if ($response->statusCode >= 400) {
            throw new MidtransApiException(
                statusCode: $response->statusCode,
                payload: $decoded,
                message: (string) ($decoded['status_message'] ?? 'Midtrans API request failed.'),
            );
        }

        // Midtrans can answer HTTP 2xx while reporting the real outcome in the
        // body, so a transport-level success is not enough to call it a success.
        $bodyStatus = isset($decoded['status_code']) ? (int) $decoded['status_code'] : 0;

        if ($bodyStatus >= 401 && $bodyStatus !== 407) {
            throw new MidtransApiException(
                statusCode: $bodyStatus,
                payload: $decoded,
                message: (string) ($decoded['status_message'] ?? 'Midtrans API returned an error status_code.'),
            );
        }

        return $decoded;
    }

    /**
     * Generates a fresh key per mutating request. Reusing one key across
     * operations makes Midtrans replay the first response for all of them.
     */
    private function resolveIdempotencyKey(string $method, string $url): ?string
    {
        if (! $this->acceptsIdempotencyKey($method, $url)) {
            return null;
        }

        if ($this->idempotencyKey !== null && $this->idempotencyKey !== '') {
            return IdempotencyKey::assertValid($this->idempotencyKey);
        }

        return IdempotencyKey::generate($this->config->idempotencyKeyPrefix);
    }

    /**
     * A request is only retried when a replay is provably harmless.
     */
    private function maxRetriesFor(string $method, string $url): int
    {
        $maxRetries = $this->config->maxRetries;

        if ($maxRetries <= 0) {
            return 0;
        }

        if ($method === 'GET') {
            return $maxRetries;
        }

        // POST carrying a live Idempotency-Key: Midtrans replays the first response.
        if ($this->acceptsIdempotencyKey($method, $url)) {
            return $maxRetries;
        }

        // The DELETE and PATCH endpoints used here only drive terminal state
        // (void, delete, convert); a replay repeats the same transition or is
        // rejected as already applied.
        if ($method === 'DELETE' || $method === 'PATCH') {
            return $maxRetries;
        }

        // Tokenization and payment-account POSTs have no server-side replay
        // protection, so a retry could create a second binding.
        return 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertRefundKeyPresent(array $payload): void
    {
        if ($this->config->maxRetries <= 0) {
            return;
        }

        $refundKey = $payload['refund_key'] ?? null;

        if (is_string($refundKey) && trim($refundKey) !== '') {
            return;
        }

        throw new MidtransException(
            'refund_key is required when retries are enabled. Midtrans treats a refund without '
            .'refund_key as a new refund, and the Idempotency-Key header only protects a five-minute '
            .'window, so a retry can refund twice. Pass a stable refund_key or set maxRetries: 0.'
        );
    }

    /**
     * Midtrans honours Idempotency-Key on POST only, and never on the tokenization
     * or payment-account endpoints.
     *
     * @see https://docs.midtrans.com/reference/api-headers
     */
    private function acceptsIdempotencyKey(string $method, string $url): bool
    {
        if ($method !== 'POST') {
            return false;
        }

        // Matched anywhere in the path, not just at the start: a base URL
        // override may prefix these with a proxy path.
        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach (self::IDEMPOTENCY_UNSUPPORTED_PATHS as $unsupported) {
            if (str_contains($path, $unsupported)) {
                return false;
            }
        }

        return true;
    }

    private function assertClientKeyPresent(): void
    {
        if ($this->config->clientKey === null || $this->config->clientKey === '') {
            throw new MidtransException('Client key is required for this card endpoint.');
        }
    }
}
