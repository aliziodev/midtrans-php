<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Support\IdempotencyKey;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyTest extends TestCase
{
    public function testGenerateProducesUniqueKeysWithPrefix(): void
    {
        $one = IdempotencyKey::generate('sdk');
        $two = IdempotencyKey::generate('sdk');

        self::assertStringStartsWith('sdk-', $one);
        self::assertStringStartsWith('sdk-', $two);
        self::assertNotSame($one, $two);
    }

    public function test_generated_key_never_exceeds_midtrans_limit(): void
    {
        self::assertLessThanOrEqual(
            IdempotencyKey::MAX_LENGTH,
            strlen(IdempotencyKey::generate(str_repeat('a', IdempotencyKey::maxPrefixLength()))),
        );
    }

    public function test_generate_rejects_prefix_that_would_be_ignored_by_midtrans(): void
    {
        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('Midtrans ignores keys longer than 46');

        IdempotencyKey::generate('my-app-payment-service');
    }

    public function test_assert_valid_rejects_empty_and_oversized_keys(): void
    {
        self::assertSame('ok-key', IdempotencyKey::assertValid('ok-key'));

        $this->expectException(MidtransException::class);
        IdempotencyKey::assertValid(str_repeat('x', IdempotencyKey::MAX_LENGTH + 1));
    }
}
