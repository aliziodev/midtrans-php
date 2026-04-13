<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Webhooks;

final class SnapBiWebhookVerifier
{
    /**
     * @param  array<string, mixed>  $body
     */
    public static function verify(
        array $body,
        string $signature,
        string $timestamp,
        string $notificationUrlPath,
        string $publicKey,
        string $httpMethod = 'POST',
    ): bool {
        $payloadJson = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            return false;
        }

        $bodyHash = hash('sha256', $payloadJson);
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

        openssl_free_key($publicKeyResource);

        return $result === 1;
    }
}
