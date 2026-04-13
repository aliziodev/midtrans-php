<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Exceptions;

final class MidtransApiException extends MidtransException
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $payload,
        string $message,
    ) {
        parent::__construct($message, $statusCode);
    }
}
