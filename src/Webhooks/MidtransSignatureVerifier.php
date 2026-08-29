<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Webhooks;

final class MidtransSignatureVerifier
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function verify(array $payload, string $serverKey): bool
    {
        $expected = self::generate($payload, $serverKey);
        $actual = (string) ($payload['signature_key'] ?? '');

        return $actual !== '' && hash_equals($expected, $actual);
    }

    /**
     * Verifies straight from the raw request body.
     *
     * Prefer this over verify(): the signature covers gross_amount as the exact
     * string Midtrans sent ("10000.00"), and a framework or mapper that casts it
     * to a float turns it into "10000" and breaks verification.
     *
     * A valid signature proves authenticity, not freshness — a genuine
     * notification can be replayed. Always re-check the transaction with
     * MidtransClient::transactionStatus() before releasing goods.
     */
    public static function verifyRaw(string $rawBody, string $serverKey): bool
    {
        /** @var mixed $payload */
        $payload = json_decode($rawBody, true, 512, JSON_BIGINT_AS_STRING);

        if (! is_array($payload)) {
            return false;
        }

        /** @var array<string, mixed> $payload */
        return self::verify($payload, $serverKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function generate(array $payload, string $serverKey): string
    {
        $orderId = (string) ($payload['order_id'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $grossAmount = (string) ($payload['gross_amount'] ?? '');

        return hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);
    }
}
