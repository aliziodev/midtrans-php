<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Support\Sdk;
use PHPUnit\Framework\TestCase;

final class SdkTest extends TestCase
{
    public function test_version_is_resolved(): void
    {
        self::assertNotSame('', Sdk::version());
    }

    public function test_user_agent_identifies_the_sdk_and_runtime(): void
    {
        $userAgent = Sdk::userAgent();

        self::assertStringStartsWith('aliziodev-midtrans-php/', $userAgent);
        self::assertStringContainsString('php/'.PHP_VERSION, $userAgent);
    }
}
