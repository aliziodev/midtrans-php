<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

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
}
