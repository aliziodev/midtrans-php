<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Webhooks\SnapBiWebhookVerifier;
use PHPUnit\Framework\TestCase;

final class SnapBiWebhookVerifierTest extends TestCase
{
    public function test_verify_returns_false_for_invalid_signature(): void
    {
        $body = ['hello' => 'world'];
        $signature = base64_encode('invalid-signature');

        self::assertFalse(SnapBiWebhookVerifier::verify(
            body: $body,
            signature: $signature,
            timestamp: '2026-01-01T00:00:00+00:00',
            notificationUrlPath: '/v1.0/debit/notify',
            publicKey: 'invalid-public-key',
        ));
    }
}
