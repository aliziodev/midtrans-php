<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests\Support;

use Aliziodev\MidtransPhp\Http\HttpResponse;
use Aliziodev\MidtransPhp\Http\Transport;

final class FakeTransport implements Transport
{
    /** @var array<int, array<string, mixed>> */
    public array $requests = [];

    /** @var array<int, HttpResponse> */
    private array $queue = [];

    public function pushResponse(HttpResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $jsonBody,
        int $timeoutSeconds,
        int $maxRetries = 0,
        int $retryDelayMs = 0,
    ): HttpResponse {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'jsonBody' => $jsonBody,
            'timeoutSeconds' => $timeoutSeconds,
            'maxRetries' => $maxRetries,
            'retryDelayMs' => $retryDelayMs,
        ];

        if ($this->queue === []) {
            return new HttpResponse(200, json_encode(['ok' => true]) ?: '{}');
        }

        return array_shift($this->queue);
    }
}
