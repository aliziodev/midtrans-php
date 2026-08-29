<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\SnapBi;

use Aliziodev\MidtransPhp\Exceptions\MidtransException;

/**
 * X-EXTERNAL-ID is Snap-BI's idempotency key: merchant-generated, unique per
 * request, with a 24-hour TTL. Reuse it when retrying the same logical
 * operation, never across different ones.
 *
 * @see https://docs.midtrans.com/reference/signature-generation
 */
final class ExternalId
{
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @throws MidtransException
     */
    public static function assertValid(string $externalId): string
    {
        if (trim($externalId) === '') {
            throw new MidtransException(
                'X-EXTERNAL-ID must not be empty: it is the only replay protection Snap-BI offers '
                .'for a retried request. Use SnapBi\ExternalId::generate() to create one.'
            );
        }

        return $externalId;
    }
}
