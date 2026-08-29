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

        return self::truncateUtf8($body, self::MAX_BODY_EXCERPT)
            .sprintf('... [%d bytes truncated]', strlen($body) - self::MAX_BODY_EXCERPT);
    }

    /**
     * substr() cuts bytes rather than characters, so it can split a multibyte
     * character in half and leave the message invalid UTF-8. json_encode() then
     * returns false for it, and a JSON log formatter drops the line — exactly
     * when the log was needed most.
     */
    public static function truncateUtf8(string $value, int $maxBytes): string
    {
        $cut = substr($value, 0, $maxBytes);

        // At most three bytes are ever shaved off: the longest UTF-8 tail.
        while ($cut !== '' && preg_match('//u', $cut) !== 1) {
            $cut = substr($cut, 0, -1);
        }

        return $cut;
    }

    private static function redact(string $body): string
    {
        // Long digit runs are almost always a PAN; keep the shape, drop the value.
        return (string) preg_replace('/\b\d{12,19}\b/', '[redacted]', $body);
    }
}
