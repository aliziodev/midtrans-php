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
