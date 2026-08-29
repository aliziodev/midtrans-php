<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Http\HttpResponse;
use Aliziodev\MidtransPhp\MidtransClient;
use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;
use Aliziodev\MidtransPhp\Support\IdempotencyKey;
use Aliziodev\MidtransPhp\Tests\Support\FakeTransport;
use Aliziodev\MidtransPhp\Webhooks\SnapBiWebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the branches that only run when something has already gone wrong.
 *
 * These are the paths a payment integration hits at its worst moment, so they
 * need to fail in a shape the caller can act on rather than in a stray notice.
 */
final class ErrorPathsTest extends TestCase
{
    public function test_gateway_html_instead_of_json_is_reported_as_an_invalid_response(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '<html><body>502 Bad Gateway</body></html>'));

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('invalid JSON response');

        $this->coreClient($transport)->getTransactionStatus('ORDER-1');
    }

    public function test_an_unencodable_payload_is_rejected_before_the_request(): void
    {
        $transport = new FakeTransport;

        try {
            // A lone continuation byte is not valid UTF-8, so json_encode fails.
            $this->coreClient($transport)->chargeTransaction(['note' => "\xB1\x31"]);
            self::fail('Expected MidtransException was not thrown');
        } catch (MidtransException $exception) {
            self::assertStringContainsString('Unable to encode payload to JSON', $exception->getMessage());
        }

        self::assertSame([], $transport->requests, 'Nothing may reach the wire when the body cannot be built');
    }

    public function test_snap_bi_reports_a_non_json_response(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, 'upstream timeout'));

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('invalid JSON response');

        $this->snapBiClient($transport)->createVa(['partnerServiceId' => '1'], 'EXT-1', 'token-1');
    }

    public function test_snap_bi_rejects_an_unencodable_payload(): void
    {
        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('Unable to encode Snap-BI');

        $this->snapBiClient(new FakeTransport)->createVa(['note' => "\xB1\x31"], 'EXT-1', 'token-1');
    }

    public function test_an_unusable_private_key_fails_with_a_clear_message(): void
    {
        $client = new SnapBiClient(
            config: new MidtransConfig(
                serverKey: 'sb-key',
                snapBiClientId: 'client-id',
                snapBiPrivateKey: 'not-a-private-key',
                snapBiClientSecret: 'secret',
                snapBiPartnerId: 'partner',
            ),
            transport: new FakeTransport,
        );

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('private key could not be read');

        $client->getAccessToken();
    }

    /**
     * Each entry blanks exactly one credential, so the message must name that one.
     *
     * @return array<string, array{0: int, 1: string}>
     */
    public static function missingCredentialProvider(): array
    {
        return [
            'client id' => [0, 'client ID'],
            'private key' => [1, 'private key'],
            'client secret' => [2, 'client secret'],
            'partner id' => [3, 'partner ID'],
        ];
    }

    #[DataProvider('missingCredentialProvider')]
    public function test_every_missing_snap_bi_credential_is_named(int $blankIndex, string $expected): void
    {
        $credentials = ['client-id', 'private-key', 'secret', 'partner'];
        $credentials[$blankIndex] = null;

        $client = new SnapBiClient(
            config: new MidtransConfig(
                serverKey: 'sb-key',
                snapBiClientId: $credentials[0],
                snapBiPrivateKey: $credentials[1],
                snapBiClientSecret: $credentials[2],
                snapBiPartnerId: $credentials[3],
            ),
            transport: new FakeTransport,
        );

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage($expected);

        $client->createVa(['partnerServiceId' => '1'], 'EXT-1', 'token-1');
    }

    public function test_an_empty_prefix_still_produces_a_usable_key(): void
    {
        $key = IdempotencyKey::generate('');

        self::assertSame(32, strlen($key));
        self::assertStringNotContainsString('-', $key);
    }

    public function test_a_prefix_of_only_dashes_is_treated_as_empty(): void
    {
        self::assertSame(32, strlen(IdempotencyKey::generate('---')));
    }

    public function test_an_empty_key_is_rejected(): void
    {
        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('must not be empty');

        IdempotencyKey::assertValid('');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unusableSignatureProvider(): array
    {
        return [
            'empty signature' => ['', '2026-01-01T00:00:00+00:00', 'key'],
            'empty timestamp' => ['c2ln', '', 'key'],
            'empty public key' => ['c2ln', '2026-01-01T00:00:00+00:00', ''],
        ];
    }

    #[DataProvider('unusableSignatureProvider')]
    public function test_webhook_verification_rejects_unusable_input(string $signature, string $timestamp, string $publicKey): void
    {
        self::assertFalse(SnapBiWebhookVerifier::verify(
            rawBody: '{}',
            signature: $signature,
            timestamp: $timestamp,
            notificationUrlPath: '/v1.0/debit/notify',
            publicKey: $publicKey,
        ));
    }

    public function test_webhook_verification_rejects_an_unparsable_timestamp(): void
    {
        self::assertFalse(SnapBiWebhookVerifier::verify(
            rawBody: '{}',
            signature: 'c2ln',
            timestamp: 'not a date',
            notificationUrlPath: '/v1.0/debit/notify',
            publicKey: (string) file_get_contents(__DIR__.'/Fixtures/snapbi_test_public.pem'),
        ));
    }

    public function test_webhook_verification_rejects_a_non_base64_signature(): void
    {
        self::assertFalse(SnapBiWebhookVerifier::verify(
            rawBody: '{}',
            signature: 'not base64 !!!',
            timestamp: gmdate('c'),
            notificationUrlPath: '/v1.0/debit/notify',
            publicKey: (string) file_get_contents(__DIR__.'/Fixtures/snapbi_test_public.pem'),
        ));
    }

    private function coreClient(FakeTransport $transport): MidtransClient
    {
        return new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );
    }

    private function snapBiClient(FakeTransport $transport): SnapBiClient
    {
        return new SnapBiClient(
            config: new MidtransConfig(
                serverKey: 'sb-key',
                maxRetries: 0,
                snapBiClientId: 'client-id',
                snapBiPrivateKey: (string) file_get_contents(__DIR__.'/Fixtures/snapbi_test_private.pem'),
                snapBiClientSecret: 'secret',
                snapBiPartnerId: 'partner',
            ),
            transport: $transport,
        );
    }
}
