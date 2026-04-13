<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Http;

final class HttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {}
}
