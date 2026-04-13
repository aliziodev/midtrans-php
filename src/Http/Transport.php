<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Http;

interface Transport
{
    /**
     * @param array<string, string> $headers
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $jsonBody,
        int $timeoutSeconds,
        int $maxRetries = 0,
        int $retryDelayMs = 0,
    ): HttpResponse;
}
