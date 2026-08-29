<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Webhooks;

final class SnapBiWebhookVerifier
{
    /**
     * Default tolerance for X-TIMESTAMP drift, in seconds.
     */
    public const DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Verifies a Snap-BI notification.
     *
     * stringToSign = HTTPMethod + ":" + EndpointUrl + ":"
     *              + Lowercase(HexEncode(SHA-256(minify(RequestBody)))) + ":" + TimeStamp
     *
     * $rawBody MUST be the exact bytes Midtrans sent (in Laravel:
     * $request->getContent()). Re-encoding a decoded array cannot reproduce them —
     * an empty JSON object comes back as [] and the hash never matches.
     *
     * @param  string  $rawBody  the untouched request body
     * @param  int|null  $toleranceSeconds  null disables the replay window check
     *
     * @see https://docs.midtrans.com/reference/signature-generation
     */
    public static function verify(
        string $rawBody,
        string $signature,
        string $timestamp,
        string $notificationUrlPath,
        string $publicKey,
        string $httpMethod = 'POST',
        ?int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): bool {
        if ($signature === '' || $timestamp === '' || $publicKey === '') {
            return false;
        }

        if ($toleranceSeconds !== null && ! self::timestampIsFresh($timestamp, $toleranceSeconds)) {
            return false;
        }

        $bodyHash = strtolower(hash('sha256', $rawBody));
        $raw = strtoupper($httpMethod).':'.$notificationUrlPath.':'.$bodyHash.':'.$timestamp;

        $signatureBinary = base64_decode($signature, true);
        if ($signatureBinary === false) {
            return false;
        }

        $publicKeyResource = openssl_pkey_get_public($publicKey);
        if ($publicKeyResource === false) {
            return false;
        }

        $result = openssl_verify(
            $raw,
            $signatureBinary,
            $publicKeyResource,
            OPENSSL_ALGO_SHA256,
        );

        // openssl_free_key() is deprecated since PHP 8.0; the key is released
        // when the OpenSSLAsymmetricKey goes out of scope.
        unset($publicKeyResource);

        return $result === 1;
    }

    /**
     * Signature validity alone does not stop a replay: an attacker can resend a
     * genuine notification verbatim. The X-TIMESTAMP window bounds that.
     */
    private static function timestampIsFresh(string $timestamp, int $toleranceSeconds): bool
    {
        $parsed = strtotime($timestamp);

        if ($parsed === false) {
            return false;
        }

        return abs(time() - $parsed) <= $toleranceSeconds;
    }
}
