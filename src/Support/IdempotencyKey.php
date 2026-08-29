<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Support;

use Aliziodev\MidtransPhp\Exceptions\MidtransException;

final class IdempotencyKey
{
    /**
     * Midtrans silently ignores an Idempotency-Key longer than this, which would
     * turn the retry guard into a false sense of safety.
     *
     * @see https://docs.midtrans.com/reference/api-headers
     */
    public const MAX_LENGTH = 46;

    /**
     * Midtrans caches the response of a key for five minutes and returns it for
     * any later request carrying the same key, regardless of body or endpoint.
     */
    public const TTL_SECONDS = 300;

    public static function generate(string $prefix = 'midtrans'): string
    {
        $prefix = trim($prefix, '-');

        $key = $prefix === ''
            ? bin2hex(random_bytes(16))
            : sprintf('%s-%s', $prefix, bin2hex(random_bytes(16)));

        return self::assertValid($key);
    }

    /**
     * @throws MidtransException when Midtrans would ignore the key
     */
    public static function assertValid(string $key): string
    {
        if ($key === '') {
            throw new MidtransException('Idempotency-Key must not be empty.');
        }

        if (strlen($key) > self::MAX_LENGTH) {
            throw new MidtransException(sprintf(
                'Idempotency-Key is %d characters; Midtrans ignores keys longer than %d, '
                .'which would silently disable retry protection. Use a shorter prefix.',
                strlen($key),
                self::MAX_LENGTH,
            ));
        }

        return $key;
    }

    /**
     * Longest prefix that still leaves room for the 32-character random suffix.
     */
    public static function maxPrefixLength(): int
    {
        return self::MAX_LENGTH - 33;
    }
}
