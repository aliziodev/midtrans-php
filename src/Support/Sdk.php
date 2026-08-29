<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Support;

use Composer\InstalledVersions;

final class Sdk
{
    public const PACKAGE = 'aliziodev/midtrans-php';

    /**
     * Used when the package runs outside a Composer install (e.g. vendored source).
     */
    public const FALLBACK_VERSION = '2.0.0';

    public static function version(): string
    {
        if (class_exists(InstalledVersions::class)) {
            try {
                return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? self::FALLBACK_VERSION;
            } catch (\OutOfBoundsException) {
                return self::FALLBACK_VERSION;
            }
        }

        return self::FALLBACK_VERSION;
    }

    public static function userAgent(): string
    {
        return sprintf('aliziodev-midtrans-php/%s (php/%s)', self::version(), PHP_VERSION);
    }
}
