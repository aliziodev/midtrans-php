<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
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
}
