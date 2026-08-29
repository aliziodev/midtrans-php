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
}
