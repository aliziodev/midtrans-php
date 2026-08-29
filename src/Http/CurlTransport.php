<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Http;

use Aliziodev\MidtransPhp\Exceptions\MidtransException;

final class CurlTransport implements Transport
{
    /**
     * Upper bound for a single backoff wait, so an aggressive maxRetries cannot
     * park a web request for minutes.
     */
    public const MAX_BACKOFF_MS = 8_000;

    /**
     * Ceiling for a server-supplied Retry-After, for the same reason.
     */
    public const MAX_RETRY_AFTER_MS = 30_000;

    /**
     * @param  array<string, string>  $headers
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $jsonBody,
        int $timeoutSeconds,
        int $maxRetries = 0,
        int $retryDelayMs = 0,
    ): HttpResponse {
        $attempt = 0;
        $maxAttempts = max(1, $maxRetries + 1);
        $lastTransportError = null;

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key.': '.$value;
        }

        while ($attempt < $maxAttempts) {
            $attempt++;

            $handle = curl_init($url);

            if (! $handle instanceof \CurlHandle) {
                throw MidtransException::transportError('Unable to initialize cURL handle.');
            }

            /** @var array<string, string> $responseHeaders */
            $responseHeaders = [];

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_TIMEOUT => $timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
                // Explicit rather than relying on libcurl defaults: this SDK carries
                // the server key on every request.
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                // A redirect would replay the Authorization header to another host.
                CURLOPT_FOLLOWLOCATION => false,
                // Pin to the scheme the caller actually asked for, so a malformed
                // base URL cannot turn into file://, scp:// and friends.
                CURLOPT_PROTOCOLS => str_starts_with($url, 'http://')
                    ? CURLPROTO_HTTP
                    : CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);

                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return strlen($line);
                },
            ]);

            if ($jsonBody !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $jsonBody);
            }

            $responseBody = curl_exec($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if (! is_string($responseBody)) {
                $lastTransportError = curl_error($handle) ?: 'Unknown cURL transport failure.';
                curl_close($handle);

                if ($attempt >= $maxAttempts) {
                    break;
                }

                $this->sleepMs($this->backoffMs($retryDelayMs, $attempt));

                continue;
            }

            curl_close($handle);

            $response = new HttpResponse($statusCode, $responseBody, $responseHeaders);

            if (! $this->isRetryableHttpStatus($statusCode) || $attempt >= $maxAttempts) {
                return $response;
            }

            $this->sleepMs(
                $this->retryAfterMs($response) ?? $this->backoffMs($retryDelayMs, $attempt)
            );
        }

        // Falling through means the final attempt failed at transport level. An
        // earlier attempt's 5xx is not reported as this request's outcome: the
        // outcome is genuinely unknown.
        throw MidtransException::transportError($lastTransportError ?? 'Unknown cURL transport failure.');
    }

    private function isRetryableHttpStatus(int $statusCode): bool
    {
        return $statusCode === 429 || ($statusCode >= 500 && $statusCode <= 599);
    }

    /**
     * Exponential backoff with full jitter, so retries from many workers do not
     * hit Midtrans in lockstep after a shared outage.
     */
    private function backoffMs(int $retryDelayMs, int $attempt): int
    {
        if ($retryDelayMs <= 0) {
            return 0;
        }

        $ceiling = min($retryDelayMs * (2 ** ($attempt - 1)), self::MAX_BACKOFF_MS);

        return random_int((int) ($ceiling / 2), (int) $ceiling);
    }

    /**
     * Honours Retry-After on 429, in both delta-seconds and HTTP-date form.
     */
    private function retryAfterMs(HttpResponse $response): ?int
    {
        $retryAfter = $response->header('Retry-After');

        if ($retryAfter === null || $retryAfter === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            return min((int) $retryAfter * 1000, self::MAX_RETRY_AFTER_MS);
        }

        $timestamp = strtotime($retryAfter);

        if ($timestamp === false) {
            return null;
        }

        return min(max($timestamp - time(), 0) * 1000, self::MAX_RETRY_AFTER_MS);
    }

    private function sleepMs(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
