<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Http;

final class HttpResponse
{
    /** @var array<string, string> lowercased header names */
    public readonly array $headers;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        array $headers = [],
    ) {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
