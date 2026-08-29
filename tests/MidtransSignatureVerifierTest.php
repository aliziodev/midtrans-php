<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Webhooks\MidtransSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class MidtransSignatureVerifierTest extends TestCase
{
    public function testGenerateAndVerifySignature(): void
    {
        $payload = [
            'order_id' => 'ORDER-123',
            'status_code' => '200',
            'gross_amount' => '10000.00',
        ];

        $serverKey = 'SB-Mid-server-test';

        $payload['signature_key'] = MidtransSignatureVerifier::generate($payload, $serverKey);

        self::assertTrue(MidtransSignatureVerifier::verify($payload, $serverKey));
        self::assertFalse(MidtransSignatureVerifier::verify($payload, 'wrong-key'));
    }

    public function test_verify_raw_reads_the_exact_gross_amount_string(): void
    {
        $serverKey = 'SB-Mid-server-test';
        $payload = ['order_id' => 'ORDER-123', 'status_code' => '200', 'gross_amount' => '10000.00'];
        $payload['signature_key'] = MidtransSignatureVerifier::generate($payload, $serverKey);

        $rawBody = (string) json_encode($payload);

        self::assertTrue(MidtransSignatureVerifier::verifyRaw($rawBody, $serverKey));
        self::assertFalse(MidtransSignatureVerifier::verifyRaw($rawBody, 'wrong-key'));
    }

    public function test_verify_raw_rejects_non_json_bodies(): void
    {
        self::assertFalse(MidtransSignatureVerifier::verifyRaw('<html>gateway timeout</html>', 'key'));
        self::assertFalse(MidtransSignatureVerifier::verifyRaw('', 'key'));
    }

    public function test_verify_rejects_payload_without_signature_key(): void
    {
        self::assertFalse(MidtransSignatureVerifier::verify(
            ['order_id' => 'ORDER-1', 'status_code' => '200', 'gross_amount' => '10000.00'],
            'SB-Mid-server-test',
        ));
    }
}
