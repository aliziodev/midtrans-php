<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Http;

use Aliziodev\MidtransPhp\Exceptions\MidtransException;

final class CurlTransport implements Transport
{
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
        $lastResponse = null;

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key.': '.$value;
        }

        while ($attempt < $maxAttempts) {
            $attempt++;

            $handle = curl_init($url);

            if (! is_resource($handle) && ! $handle instanceof \CurlHandle) {
                throw MidtransException::transportError('Unable to initialize cURL handle.');
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_TIMEOUT => $timeoutSeconds,
            ]);

            if ($jsonBody !== null) {
                curl_setopt($handle, CURLOPT_POSTFIELDS, $jsonBody);
            }

            $responseBody = curl_exec($handle);
            $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if ($responseBody === false) {
                $lastTransportError = curl_error($handle);
                curl_close($handle);

                if ($attempt < $maxAttempts && $retryDelayMs > 0) {
                    usleep($retryDelayMs * 1000);
                }

                continue;
            }

            curl_close($handle);

            if (! is_string($responseBody)) {
                throw MidtransException::transportError('Unexpected cURL response type.');
            }

            $lastResponse = new HttpResponse($statusCode, $responseBody);

            if (! $this->isRetryableHttpStatus($statusCode) || $attempt >= $maxAttempts) {
                return $lastResponse;
            }

            if ($retryDelayMs > 0) {
                usleep($retryDelayMs * 1000);
            }
        }

        if ($lastResponse instanceof HttpResponse) {
            return $lastResponse;
        }

        throw MidtransException::transportError($lastTransportError ?? 'Unknown cURL transport failure.');
    }

    private function isRetryableHttpStatus(int $statusCode): bool
    {
        return $statusCode === 429 || ($statusCode >= 500 && $statusCode <= 599);
    }
}
