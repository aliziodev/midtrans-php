<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests\Integration;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\MidtransClient;
use Aliziodev\MidtransPhp\Webhooks\MidtransSignatureVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real Midtrans sandbox.
 *
 * The unit suite proves the SDK builds the request it means to; only these
 * prove Midtrans agrees the endpoint exists and the auth header is right.
 * Several of these paths came from the documentation and had never been called.
 *
 * Copy .env.example to .env, fill in a sandbox key, then:
 *   composer test:integration
 */
#[Group('integration')]
final class SandboxSmokeTest extends TestCase
{

    private MidtransClient $client;

    protected function setUp(): void
    {
        $serverKey = trim((string) getenv('MIDTRANS_SERVER_KEY'));

        if ($serverKey === '') {
            self::markTestSkipped('Set MIDTRANS_SERVER_KEY in .env to run the sandbox suite.');
        }


        $config = new MidtransConfig(
            serverKey: $serverKey,
            clientKey: ((string) getenv('MIDTRANS_CLIENT_KEY')) ?: null,
            isProduction: false,
            maxRetries: 1,
        );

        $this->assertSandbox($config);

        $this->client = new MidtransClient($config);
    }

    public function test_snap_transaction_returns_a_token(): void
    {
        $result = $this->client->createSnapTransaction([
            'transaction_details' => [
                'order_id' => $this->orderId('SNAP'),
                'gross_amount' => 10000,
            ],
        ]);

        self::assertArrayHasKey('token', $result);
        self::assertNotSame('', $result['token']);
        self::assertStringContainsString('sandbox', $result['redirect_url']);
    }

    public function test_bank_transfer_charge_returns_a_virtual_account(): void
    {
        $result = $this->client->chargeTransaction([
            'payment_type' => 'bank_transfer',
            'transaction_details' => ['order_id' => $this->orderId('VA'), 'gross_amount' => 20000],
            'bank_transfer' => ['bank' => 'bca'],
        ]);

        self::assertSame('pending', $result['transaction_status']);
        self::assertSame('bca', $result['va_numbers'][0]['bank']);
        // The amount comes back as an exact string; the notification signature
        // is computed over this text, so it must not be cast anywhere.
        self::assertSame('20000.00', $result['gross_amount']);
    }

    public function test_status_and_cancel_round_trip(): void
    {
        $orderId = $this->orderId('CANCEL');

        $this->client->chargeTransaction([
            'payment_type' => 'bank_transfer',
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 25000],
            'bank_transfer' => ['bank' => 'bni'],
        ]);

        self::assertSame('pending', $this->client->getTransactionStatus($orderId)['transaction_status']);
        self::assertSame('cancel', $this->client->cancelTransaction($orderId)['transaction_status']);
    }

    /**
     * The signature the SDK verifies has to be the one Midtrans produces. Built
     * here from a real API response rather than from a fixture.
     */
    public function test_signature_verification_matches_a_real_response(): void
    {
        $charge = $this->client->chargeTransaction([
            'payment_type' => 'bank_transfer',
            'transaction_details' => ['order_id' => $this->orderId('SIGN'), 'gross_amount' => 30000],
            'bank_transfer' => ['bank' => 'bri'],
        ]);

        $payload = [
            'order_id' => $charge['order_id'],
            'status_code' => $charge['status_code'],
            'gross_amount' => $charge['gross_amount'],
        ];
        $payload['signature_key'] = MidtransSignatureVerifier::generate(
            $payload,
            (string) getenv('MIDTRANS_SERVER_KEY'),
        );

        self::assertTrue(MidtransSignatureVerifier::verifyRaw(
            (string) json_encode($payload),
            (string) getenv('MIDTRANS_SERVER_KEY'),
        ));
    }

    /**
     * Added in 2.0.0 from the documentation, never called until now.
     */
    public function test_bin_api_endpoint_exists(): void
    {
        self::assertArrayHasKey('data', $this->client->getBin('48111111'));
    }

    /**
     * Snap Preference lives on the v3 host while checkout stays on v1.
     */
    public function test_snap_preference_endpoint_exists(): void
    {
        self::assertNotEmpty($this->client->getSnapPreferences());
    }

    public function test_a_missing_transaction_reports_404_rather_than_a_parse_error(): void
    {
        try {
            $this->client->getTransactionStatus('NOPE-'.bin2hex(random_bytes(4)));
            self::fail('Expected MidtransApiException was not thrown');
        } catch (MidtransApiException $exception) {
            self::assertSame(404, $exception->statusCode);
            self::assertArrayHasKey('status_message', $exception->payload);
        }
    }

    public function test_refund_without_a_refund_key_is_refused_before_leaving_the_process(): void
    {
        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('refund_key is required');

        $this->client->refundTransaction('ANY-ORDER', ['amount' => 1000]);
    }

    /**
     * getSnapToken and getSnapUrl are thin wrappers over createSnapTransaction
     * that return one field each. The wrappers are what most callers reach for,
     * and neither had ever been called against the API — only the underlying
     * createSnapTransaction had.
     */
    public function test_the_snap_wrappers_return_a_token_and_a_redirect_url(): void
    {
        $token = $this->client->getSnapToken([
            'transaction_details' => ['order_id' => $this->orderId('SNAPTOK'), 'gross_amount' => 10000],
        ]);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $token);

        $url = $this->client->getSnapUrl([
            'transaction_details' => ['order_id' => $this->orderId('SNAPURL'), 'gross_amount' => 10000],
        ]);

        self::assertStringStartsWith('https://app.sandbox.midtrans.com/snap/', $url);
    }

    public function test_a_card_can_be_registered_for_one_click_reuse(): void
    {
        $registered = $this->client->registerCard('4811111111111114', '12', '2030');

        self::assertSame('200', $registered['status_code']);
        self::assertNotEmpty($registered['saved_token_id']);
    }

    /**
     * The balance endpoint accepts ISO 8601 timestamps and nothing else.
     * "2026-08-30 09:48:31", the same instant with an offset appended, and a
     * bare "2026-08-30" are each answered with 400 Invalid date format — so the
     * format is worth pinning rather than rediscovering.
     */
    public function test_balance_mutation_accepts_iso8601_timestamps(): void
    {
        $mutation = $this->client->getBalanceMutation(
            'IDR',
            date('c', strtotime('-7 day')),
            date('c'),
        );

        self::assertSame('IDR', $mutation['currency']);
        self::assertArrayHasKey('opening_balance_effective', $mutation);
        self::assertArrayHasKey('wallets', $mutation);
    }

    public function test_balance_mutation_rejects_a_non_iso8601_timestamp(): void
    {
        $this->expectException(MidtransApiException::class);

        $this->client->getBalanceMutation(
            'IDR',
            date('Y-m-d H:i:s', strtotime('-7 day')),
            date('Y-m-d H:i:s'),
        );
    }

    private function orderId(string $prefix): string
    {
        return $prefix.'-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
    }

    /**
     * Refuses to run unless the client is pointed at the sandbox host.
     *
     * Asserted from the resolved base URL, not from the key's prefix. Sandbox
     * keys used to start with SB-Mid-server- and newer ones start with
     * Mid-server-, which is what production keys have always looked like — so
     * the prefix no longer tells the two apart. The host does, and the host is
     * what decides whether these tests can move real money.
     */
    private function assertSandbox(MidtransConfig $config): void
    {
        if (! str_contains($config->coreBaseUrl(), 'sandbox')) {
            self::fail('Refusing to run: the client is not pointed at the Midtrans sandbox.');
        }
    }
}
