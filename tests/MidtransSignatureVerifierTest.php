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
}
