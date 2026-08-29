<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Webhooks\SnapBiWebhookVerifier;
use PHPUnit\Framework\TestCase;

final class SnapBiWebhookVerifierTest extends TestCase
{
    private const PATH = '/v1.0/debit/notify';

    public function test_verify_accepts_a_genuine_signature(): void
    {
        $rawBody = '{"originalPartnerReferenceNo":"ORDER-1","amount":{"value":"10000.00","currency":"IDR"}}';
        $timestamp = gmdate('c');

        self::assertTrue(SnapBiWebhookVerifier::verify(
            rawBody: $rawBody,
            signature: self::sign($rawBody, $timestamp),
            timestamp: $timestamp,
            notificationUrlPath: self::PATH,
            publicKey: self::publicKey(),
        ));
    }

    /**
     * Regression: the verifier used to take a decoded array and re-encode it.
     * An empty JSON object round-trips to [], so the hash never matched and a
     * genuine notification was rejected.
     */
    public function test_verify_accepts_payload_containing_an_empty_json_object(): void
    {
        $rawBody = '{"partnerReferenceNo":"ORDER-2","additionalInfo":{}}';
        $timestamp = gmdate('c');

        self::assertTrue(SnapBiWebhookVerifier::verify(
            rawBody: $rawBody,
            signature: self::sign($rawBody, $timestamp),
            timestamp: $timestamp,
            notificationUrlPath: self::PATH,
            publicKey: self::publicKey(),
        ));

        self::assertNotSame(
            $rawBody,
            json_encode(json_decode($rawBody, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'The re-encoded body must differ, otherwise this regression test proves nothing',
        );
    }

    public function test_verify_rejects_a_tampered_body(): void
    {
        $timestamp = gmdate('c');
        $signature = self::sign('{"amount":{"value":"10000.00"}}', $timestamp);

        self::assertFalse(SnapBiWebhookVerifier::verify(
            rawBody: '{"amount":{"value":"99999.00"}}',
            signature: $signature,
            timestamp: $timestamp,
            notificationUrlPath: self::PATH,
            publicKey: self::publicKey(),
        ));
    }

    public function test_verify_rejects_a_signature_made_for_another_path(): void
    {
        $rawBody = '{"partnerReferenceNo":"ORDER-3"}';
        $timestamp = gmdate('c');

        self::assertFalse(SnapBiWebhookVerifier::verify(
            rawBody: $rawBody,
            signature: self::sign($rawBody, $timestamp, '/v1.0/qr/qr-mpm-notify'),
            timestamp: $timestamp,
            notificationUrlPath: self::PATH,
            publicKey: self::publicKey(),
        ));
    }

    public function test_verify_rejects_a_replayed_notification_outside_the_window(): void
    {
        $rawBody = '{"partnerReferenceNo":"ORDER-4"}';
        $timestamp = gmdate('c', time() - 3600);
        $signature = self::sign($rawBody, $timestamp);

        self::assertFalse(
            SnapBiWebhookVerifier::verify(
                rawBody: $rawBody,
                signature: $signature,
                timestamp: $timestamp,
                notificationUrlPath: self::PATH,
                publicKey: self::publicKey(),
            ),
            'An hour-old notification must fall outside the default tolerance',
        );

        self::assertTrue(
            SnapBiWebhookVerifier::verify(
                rawBody: $rawBody,
                signature: $signature,
                timestamp: $timestamp,
                notificationUrlPath: self::PATH,
                publicKey: self::publicKey(),
                toleranceSeconds: null,
            ),
            'The same notification must still verify when the window check is disabled',
        );
    }

    public function test_verify_returns_false_for_invalid_signature(): void
    {
        self::assertFalse(SnapBiWebhookVerifier::verify(
            rawBody: '{"hello":"world"}',
            signature: base64_encode('invalid-signature'),
            timestamp: gmdate('c'),
            notificationUrlPath: self::PATH,
            publicKey: self::publicKey(),
        ));
    }

    public function test_verify_returns_false_for_unusable_public_key(): void
    {
        self::assertFalse(SnapBiWebhookVerifier::verify(
            rawBody: '{"hello":"world"}',
            signature: base64_encode('whatever'),
            timestamp: gmdate('c'),
            notificationUrlPath: self::PATH,
            publicKey: 'not-a-public-key',
        ));
    }

    private static function sign(string $rawBody, string $timestamp, string $path = self::PATH): string
    {
        $stringToSign = 'POST:'.$path.':'.strtolower(hash('sha256', $rawBody)).':'.$timestamp;

        $signature = '';
        openssl_sign($stringToSign, $signature, self::privateKey(), OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    private static function privateKey(): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/snapbi_test_private.pem');
    }

    private static function publicKey(): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/snapbi_test_public.pem');
    }
}
