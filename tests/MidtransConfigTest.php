<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use PHPUnit\Framework\TestCase;

final class MidtransConfigTest extends TestCase
{
    public function test_sandbox_base_urls(): void
    {
        $config = new MidtransConfig(serverKey: 'sb-key', isProduction: false);

        self::assertSame('https://api.sandbox.midtrans.com', $config->coreBaseUrl());
        self::assertSame('https://app.sandbox.midtrans.com/snap/v1', $config->snapBaseUrl());
        self::assertSame('https://api.sandbox.midtrans.com', $config->snapBiBaseUrl());
    }

    public function test_production_base_urls(): void
    {
        $config = new MidtransConfig(serverKey: 'prod-key', isProduction: true);

        self::assertSame('https://api.midtrans.com', $config->coreBaseUrl());
        self::assertSame('https://app.midtrans.com/snap/v1', $config->snapBaseUrl());
        self::assertSame('https://api.midtrans.com', $config->snapBiBaseUrl());
    }

    public function test_can_override_all_base_urls(): void
    {
        $config = new MidtransConfig(
            serverKey: 'custom-key',
            isProduction: true,
            coreBaseUrlOverride: 'https://proxy.example.com/core/',
            snapBaseUrlOverride: 'https://proxy.example.com/snap/v1/',
            snapBiBaseUrlOverride: 'https://proxy.example.com/snap-bi/',
        );

        self::assertSame('https://proxy.example.com/core', $config->coreBaseUrl());
        self::assertSame('https://proxy.example.com/snap/v1', $config->snapBaseUrl());
        self::assertSame('https://proxy.example.com/snap-bi', $config->snapBiBaseUrl());
    }

    public function test_debug_info_masks_credentials(): void
    {
        $config = new MidtransConfig(
            serverKey: 'Mid-server-SUPERSECRET123',
            clientKey: 'Mid-client-PUBLIC456789',
            snapBiClientSecret: 'snap-bi-client-secret-value',
            snapBiPrivateKey: "-----BEGIN PRIVATE KEY-----
MII...
-----END PRIVATE KEY-----",
        );

        $dumped = var_export($config->__debugInfo(), true);

        self::assertStringNotContainsString('SUPERSECRET123', $dumped);
        self::assertStringNotContainsString('snap-bi-client-secret-value', $dumped);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $dumped);
        self::assertStringContainsString('[redacted]', $dumped);
    }

    public function test_debug_info_keeps_non_secret_fields_readable(): void
    {
        $info = (new MidtransConfig(serverKey: 'sb-key', isProduction: true, snapBiPartnerId: 'BMRI'))->__debugInfo();

        self::assertTrue($info['isProduction']);
        self::assertSame('BMRI', $info['snapBiPartnerId']);
        self::assertSame(30, $info['timeoutSeconds']);
    }

    public function test_plaintext_http_override_is_rejected(): void
    {
        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('must use https');

        new MidtransConfig(serverKey: 'k', coreBaseUrlOverride: 'http://proxy.example.com');
    }

    public function test_plaintext_http_override_is_allowed_only_with_explicit_opt_in(): void
    {
        $config = new MidtransConfig(
            serverKey: 'k',
            coreBaseUrlOverride: 'http://127.0.0.1:8080',
            allowInsecureBaseUrl: true,
        );

        self::assertSame('http://127.0.0.1:8080', $config->coreBaseUrl());
    }

    public function test_override_without_a_scheme_is_rejected(): void
    {
        $this->expectException(MidtransException::class);

        new MidtransConfig(serverKey: 'k', snapBaseUrlOverride: 'proxy.example.com/snap/v1');
    }

    public function test_idempotency_prefix_that_would_overflow_the_limit_is_rejected(): void
    {
        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('idempotencyKeyPrefix must be at most');

        new MidtransConfig(serverKey: 'k', idempotencyKeyPrefix: 'my-app-payment-service');
    }

    public function test_snap_v3_base_url_is_derived_from_snap_base_url(): void
    {
        self::assertSame(
            'https://app.sandbox.midtrans.com/snap/v3',
            (new MidtransConfig(serverKey: 'k'))->snapV3BaseUrl(),
        );
        self::assertSame(
            'https://app.midtrans.com/snap/v3',
            (new MidtransConfig(serverKey: 'k', isProduction: true))->snapV3BaseUrl(),
        );
    }
}
