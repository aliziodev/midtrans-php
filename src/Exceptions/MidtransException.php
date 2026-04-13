<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Exceptions;

use RuntimeException;

class MidtransException extends RuntimeException
{
    public static function transportError(string $message): self
    {
        return new self('Midtrans transport error: '.$message);
    }

    public static function invalidResponse(string $body): self
    {
        return new self('Midtrans invalid JSON response: '.$body);
    }
}
