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
        // Both fallbacks below only run when the package was vendored without
        // Composer, which by definition cannot happen in a Composer test run.
        if (! class_exists(InstalledVersions::class)) {
            return self::FALLBACK_VERSION; // @codeCoverageIgnore
        }

        try {
            return InstalledVersions::getPrettyVersion(self::PACKAGE) ?? self::FALLBACK_VERSION;
            // @codeCoverageIgnoreStart
        } catch (\OutOfBoundsException) {
            return self::FALLBACK_VERSION;
            // @codeCoverageIgnoreEnd
        }
    }

    public static function userAgent(): string
    {
        return sprintf('aliziodev-midtrans-php/%s (php/%s)', self::version(), PHP_VERSION);
    }
}
