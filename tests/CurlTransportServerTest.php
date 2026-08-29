<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Http\CurlTransport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Drives CurlTransport against PHP's built-in server.
 *
 * The retry loop is the part of this SDK most likely to cause a duplicate
 * charge, and its behaviour only exists in the interaction between cURL, the
 * status code and the clock. Testing it through a real socket is the only way
 * to verify attempt counts, header capture and Retry-After for real.
 */
#[Group('transport')]
final class CurlTransportServerTest extends TestCase
{
    /** @var resource|null */
    private static $process;

    private static int $port = 0;

    private static string $stderr = '';

    public static function setUpBeforeClass(): void
    {
        $router = __DIR__.'/Fixtures/router.php';
        self::$stderr = tempnam(sys_get_temp_dir(), 'midtrans-server-') ?: '';

        foreach (self::candidatePorts() as $port) {
            $process = proc_open(
                [PHP_BINARY, '-S', '127.0.0.1:'.$port, $router],
                [
                    0 => ['pipe', 'r'],
                    1 => ['file', self::$stderr, 'a'],
                    2 => ['file', self::$stderr, 'a'],
                ],
                $pipes,
                dirname(__DIR__),
            );

            if (! is_resource($process)) {
                continue;
            }

            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (self::waitUntilListening($port)) {
                self::$process = $process;
                self::$port = $port;

                return;
            }

            proc_terminate($process);
            proc_close($process);
        }

        self::fail(
            'Could not start PHP built-in server for transport tests. Server log: '
            .(string) @file_get_contents(self::$stderr)
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }

        self::$process = null;

        if (self::$stderr !== '') {
            @unlink(self::$stderr);
        }
    }

    public function test_successful_response_is_returned_with_status_and_body(): void
    {
        $response = $this->transport()->request('GET', $this->url('/ok'), [], null, 5);

        self::assertSame(200, $response->statusCode);
        self::assertSame(['ok' => true], json_decode($response->body, true));
    }

    public function test_response_headers_are_captured_case_insensitively(): void
    {
        $response = $this->transport()->request('GET', $this->url('/ok'), [], null, 5);

        self::assertSame('trace-abc-123', $response->header('X-Trace-Id'));
        self::assertSame('trace-abc-123', $response->header('x-trace-id'));
        self::assertSame('yes', $response->header('X-MULTI-WORD-HEADER'));
    }

    public function test_request_method_body_and_headers_reach_the_server(): void
    {
        $response = $this->transport()->request(
            'POST',
            $this->url('/echo'),
            ['Authorization' => 'Basic c2VjcmV0', 'Idempotency-Key' => 'idem-1', 'User-Agent' => 'sdk/1.2.3'],
            '{"order_id":"ORDER-1"}',
            5,
        );

        $echo = json_decode($response->body, true);

        self::assertSame('POST', $echo['method']);
        self::assertSame('{"order_id":"ORDER-1"}', $echo['body']);
        self::assertSame('Basic c2VjcmV0', $echo['authorization']);
        self::assertSame('idem-1', $echo['idempotency_key']);
        self::assertSame('sdk/1.2.3', $echo['user_agent']);
    }

    public function test_a_500_is_retried_until_it_succeeds(): void
    {
        $response = $this->transport()->request(
            'GET',
            $this->url('/fail-then-ok', ['key' => uniqid('retry', true), 'fail' => 2, 'status' => 500]),
            [],
            null,
            5,
            maxRetries: 3,
            retryDelayMs: 1,
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame(3, json_decode($response->body, true)['attempt'], 'Two failures then a success is three requests');
    }

    public function test_retries_stop_at_max_attempts_and_return_the_last_response(): void
    {
        $key = uniqid('exhaust', true);

        $response = $this->transport()->request(
            'GET',
            $this->url('/fail-then-ok', ['key' => $key, 'fail' => 99, 'status' => 503]),
            [],
            null,
            5,
            maxRetries: 2,
            retryDelayMs: 1,
        );

        self::assertSame(503, $response->statusCode);
        self::assertSame(3, json_decode($response->body, true)['attempt'], 'maxRetries 2 means three attempts in total');
    }

    public function test_a_400_is_not_retried(): void
    {
        $response = $this->transport()->request(
            'GET',
            $this->url('/fail-then-ok', ['key' => uniqid('client-error', true), 'fail' => 99, 'status' => 400]),
            [],
            null,
            5,
            maxRetries: 3,
            retryDelayMs: 1,
        );

        self::assertSame(400, $response->statusCode);
        self::assertSame(1, json_decode($response->body, true)['attempt'], 'A client error is the final answer');
    }

    public function test_a_429_is_retried_and_retry_after_is_waited_out(): void
    {
        $startedAt = microtime(true);

        $response = $this->transport()->request(
            'GET',
            $this->url('/retry-after', ['key' => uniqid('rate', true), 'seconds' => 1]),
            [],
            null,
            5,
            maxRetries: 1,
            retryDelayMs: 1,
        );

        $elapsed = microtime(true) - $startedAt;

        self::assertSame(200, $response->statusCode);
        self::assertGreaterThanOrEqual(
            0.9,
            $elapsed,
            'Retry-After: 1 must be honoured rather than the 1ms configured backoff',
        );
    }

    public function test_redirects_are_not_followed(): void
    {
        $response = $this->transport()->request('GET', $this->url('/redirect'), [], null, 5);

        self::assertSame(302, $response->statusCode, 'Following it would replay the Authorization header');
        self::assertSame('/ok', $response->header('Location'));
    }

    public function test_a_non_json_body_is_returned_verbatim(): void
    {
        $response = $this->transport()->request('GET', $this->url('/not-json'), [], null, 5);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('gateway timeout', $response->body);
    }

    public function test_a_connection_failure_throws_after_exhausting_retries(): void
    {
        $deadPort = self::freePort();

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('Midtrans transport error');

        // A one second budget is enough to reach the transport-error path; a
        // longer one just pays the connect timeout twice.
        $this->transport()->request(
            'GET',
            'http://127.0.0.1:'.$deadPort.'/ok',
            [],
            null,
            1,
            maxRetries: 1,
            retryDelayMs: 1,
        );
    }

    private function transport(): CurlTransport
    {
        return new CurlTransport;
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function url(string $path, array $query = []): string
    {
        return 'http://127.0.0.1:'.self::$port.$path
            .($query === [] ? '' : '?'.http_build_query($query));
    }

    /**
     * @return array<int, int>
     */
    private static function candidatePorts(): array
    {
        return array_map(static fn (): int => self::freePort(), range(1, 5));
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            return random_int(20000, 60000);
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function waitUntilListening(int $port, float $timeoutSeconds = 5.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }
}
