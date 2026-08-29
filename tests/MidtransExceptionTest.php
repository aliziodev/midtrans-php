<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use PHPUnit\Framework\TestCase;

final class MidtransExceptionTest extends TestCase
{
    public function test_invalid_response_truncates_long_bodies(): void
    {
        $message = MidtransException::invalidResponse(str_repeat('A', 5000))->getMessage();

        self::assertStringContainsString('bytes truncated', $message);
        self::assertLessThan(400, strlen($message));
    }

    public function test_invalid_response_redacts_card_like_digit_runs(): void
    {
        $message = MidtransException::invalidResponse('{"card_number":"4811111111111114"}')->getMessage();

        self::assertStringNotContainsString('4811111111111114', $message);
        self::assertStringContainsString('[redacted]', $message);
    }

    public function test_empty_body_is_reported_explicitly(): void
    {
        self::assertStringContainsString('<empty body>', MidtransException::invalidResponse('   ')->getMessage());
    }

    public function test_transport_error_is_prefixed(): void
    {
        $exception = MidtransException::transportError('Connection timed out after 30000 ms');

        self::assertStringStartsWith('Midtrans transport error: ', $exception->getMessage());
        self::assertStringContainsString('Connection timed out', $exception->getMessage());
    }

    public function test_excerpt_collapses_whitespace(): void
    {
        self::assertSame('{ "a": 1 }', MidtransException::excerpt("{\n  \"a\":   1\n}\n"));
    }

    /**
     * The failure mode reported against the official SDK in Midtrans/midtrans-php#91:
     * a byte-wise cut lands in the middle of a multibyte character, json_encode()
     * returns false for the result, and a JSON log formatter drops the line —
     * exactly when the log was needed most.
     */
    public function test_truncation_never_produces_invalid_utf8(): void
    {
        $message = MidtransException::invalidResponse(str_repeat('制', 100))->getMessage();

        self::assertSame(1, preg_match('//u', $message), 'The message must stay valid UTF-8');
        self::assertNotFalse(
            json_encode(['message' => $message]),
            'A JSON log formatter must be able to encode it',
        );
        self::assertStringContainsString('bytes truncated', $message);
    }

    public function test_truncate_utf8_keeps_whole_characters(): void
    {
        self::assertSame('abc', MidtransException::truncateUtf8('abcdef', 3));
        self::assertSame('', MidtransException::truncateUtf8('制', 2), 'Half a character is dropped entirely');
        self::assertSame('制', MidtransException::truncateUtf8('制制', 4));
    }
}
