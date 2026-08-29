<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Exceptions;

use RuntimeException;

class MidtransException extends RuntimeException
{
    /**
     * Response bodies can carry card data, customer PII or an upstream HTML error
     * page. Exception messages end up in logs and stack traces, so only a short,
     * redacted excerpt is kept.
     */
    public const MAX_BODY_EXCERPT = 200;

    public static function transportError(string $message): self
    {
        return new self('Midtrans transport error: '.$message);
    }

    public static function invalidResponse(string $body): self
    {
        return new self('Midtrans invalid JSON response: '.self::excerpt($body));
    }

    public static function excerpt(string $body): string
    {
        $body = (string) preg_replace('/\s+/', ' ', trim($body));
        $body = self::redact($body);

        if ($body === '') {
            return '<empty body>';
        }

        if (strlen($body) <= self::MAX_BODY_EXCERPT) {
            return $body;
        }

        return substr($body, 0, self::MAX_BODY_EXCERPT).sprintf('... [%d bytes truncated]', strlen($body) - self::MAX_BODY_EXCERPT);
    }

    private static function redact(string $body): string
    {
        // Long digit runs are almost always a PAN; keep the shape, drop the value.
        return (string) preg_replace('/\b\d{12,19}\b/', '[redacted]', $body);
    }
}
