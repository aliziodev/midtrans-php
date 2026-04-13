<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Support;

final class IdempotencyKey
{
    public static function generate(string $prefix = 'midtrans'): string
    {
        return sprintf('%s-%s', $prefix, bin2hex(random_bytes(16)));
    }
}
