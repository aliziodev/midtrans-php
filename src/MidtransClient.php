<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Http\CurlTransport;
use Aliziodev\MidtransPhp\Http\Transport;

final class MidtransClient
{
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
        return $this->request('POST', $this->config->coreBaseUrl().'/v2/'.rawurlencode($orderOrTransactionId).'/refund', $payload);
    }

    /** @param array<string, mixed> $payload */
    public function refundTransactionDirect(string $orderOrTransactionId, array $payload): array
    {
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
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $payload = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic '.base64_encode($this->config->serverKey.':'),
        ];

        if (strtoupper($method) !== 'GET') {
            $idempotencyKey = $this->idempotencyKey ?? $this->config->defaultIdempotencyKey;

            if ($this->config->maxRetries > 0 && ($idempotencyKey === null || $idempotencyKey === '')) {
                throw new MidtransException('Idempotency-Key is required for non-GET requests when retry is enabled.');
            }

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
        }

        $jsonBody = $payload === null ? null : json_encode($payload);

        if ($payload !== null && $jsonBody === false) {
            throw MidtransException::invalidResponse('Unable to encode payload to JSON.');
        }

        $response = $this->transport->request(
            method: $method,
            url: $url,
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
            $message = (string) ($decoded['status_message'] ?? 'Midtrans API request failed.');

            throw new MidtransApiException(
                statusCode: $response->statusCode,
                payload: $decoded,
                message: $message,
            );
        }

        return $decoded;
    }

    private function assertClientKeyPresent(): void
    {
        if ($this->config->clientKey === null || $this->config->clientKey === '') {
            throw new MidtransException('Client key is required for this card endpoint.');
        }
    }
}
