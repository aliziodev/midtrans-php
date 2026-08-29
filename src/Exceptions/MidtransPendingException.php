<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Exceptions;

/**
 * Midtrans answers HTTP 202 while an earlier request carrying the same
 * Idempotency-Key is still being processed. The body is not a final result, so
 * it must never be handed back as a successful response.
 *
 * @see https://docs.midtrans.com/reference/api-headers
 */
final class MidtransPendingException extends MidtransException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $payload,
        public readonly ?string $idempotencyKey = null,
    ) {
        parent::__construct(
            'Midtrans is still processing an earlier request with the same Idempotency-Key '
            .'(HTTP 202). Retry the same request with the same key to obtain the final result.',
            $statusCode,
        );
    }
}
