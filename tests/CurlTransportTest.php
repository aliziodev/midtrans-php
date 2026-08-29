<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Http\CurlTransport;
use Aliziodev\MidtransPhp\Http\HttpResponse;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CurlTransportTest extends TestCase
{
    public function test_response_headers_are_matched_case_insensitively(): void
    {
        $response = new HttpResponse(429, '{}', ['Retry-After' => '7', 'X-Request-Id' => 'abc']);

        self::assertSame('7', $response->header('retry-after'));
        self::assertSame('7', $response->header('RETRY-AFTER'));
        self::assertSame('abc', $response->header('X-Request-Id'));
        self::assertNull($response->header('missing'));
    }

    public function test_backoff_grows_exponentially_and_stays_jittered(): void
    {
        $backoff = $this->method('backoffMs');

        $first = $backoff->invoke(new CurlTransport, 200, 1);
        $third = $backoff->invoke(new CurlTransport, 200, 3);

        self::assertGreaterThanOrEqual(100, $first);
        self::assertLessThanOrEqual(200, $first);

        self::assertGreaterThanOrEqual(400, $third);
        self::assertLessThanOrEqual(800, $third);
    }

    public function test_backoff_is_capped(): void
    {
        self::assertLessThanOrEqual(
            CurlTransport::MAX_BACKOFF_MS,
            $this->method('backoffMs')->invoke(new CurlTransport, 1000, 20),
        );
    }

    public function test_backoff_is_disabled_when_delay_is_zero(): void
    {
        self::assertSame(0, $this->method('backoffMs')->invoke(new CurlTransport, 0, 3));
    }

    public function test_retry_after_is_honoured_in_seconds(): void
    {
        self::assertSame(
            7000,
            $this->method('retryAfterMs')->invoke(new CurlTransport, new HttpResponse(429, '{}', ['Retry-After' => '7'])),
        );
    }

    public function test_retry_after_is_honoured_as_http_date(): void
    {
        $response = new HttpResponse(429, '{}', ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 5)]);

        $delay = $this->method('retryAfterMs')->invoke(new CurlTransport, $response);

        self::assertGreaterThan(0, $delay);
        self::assertLessThanOrEqual(6000, $delay);
    }

    public function test_retry_after_is_capped_and_never_negative(): void
    {
        $retryAfter = $this->method('retryAfterMs');

        self::assertSame(
            CurlTransport::MAX_RETRY_AFTER_MS,
            $retryAfter->invoke(new CurlTransport, new HttpResponse(429, '{}', ['Retry-After' => '99999'])),
        );
        self::assertSame(
            0,
            $retryAfter->invoke(new CurlTransport, new HttpResponse(429, '{}', ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() - 600)])),
        );
    }

    public function test_missing_or_unparsable_retry_after_falls_back_to_backoff(): void
    {
        $retryAfter = $this->method('retryAfterMs');

        self::assertNull($retryAfter->invoke(new CurlTransport, new HttpResponse(429, '{}')));
        self::assertNull($retryAfter->invoke(new CurlTransport, new HttpResponse(429, '{}', ['Retry-After' => 'soon'])));
    }

    private function method(string $name): ReflectionMethod
    {
        return new ReflectionMethod(CurlTransport::class, $name);
    }
}
