<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Config;

use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Support\IdempotencyKey;
use SensitiveParameter;

final class MidtransConfig
{
    public function __construct(
        #[SensitiveParameter]
        public readonly string $serverKey,
        #[SensitiveParameter]
        public readonly ?string $clientKey = null,
        public readonly bool $isProduction = false,
        public readonly int $timeoutSeconds = 30,
        public readonly int $maxRetries = 2,
        public readonly int $retryDelayMs = 200,
        /**
         * Prefix for the per-request Idempotency-Key the client generates.
         *
         * This is deliberately NOT a fixed key: Midtrans replays the cached
         * response of a key for five minutes for any later request carrying it,
         * regardless of body or endpoint.
         */
        public readonly string $idempotencyKeyPrefix = 'midtrans',
        #[SensitiveParameter]
        public readonly ?string $snapBiClientId = null,
        #[SensitiveParameter]
        public readonly ?string $snapBiPrivateKey = null,
        #[SensitiveParameter]
        public readonly ?string $snapBiClientSecret = null,
        public readonly ?string $snapBiPartnerId = null,
        public readonly string $snapBiChannelId = '95221',
        public readonly ?string $snapBiDeviceId = null,
        public readonly ?string $coreBaseUrlOverride = null,
        public readonly ?string $snapBaseUrlOverride = null,
        public readonly ?string $snapBiBaseUrlOverride = null,
        /**
         * Escape hatch for pointing the SDK at a local mock over plain HTTP.
         * Never enable this against a real Midtrans environment: every request
         * carries the server key in an Authorization header.
         */
        public readonly bool $allowInsecureBaseUrl = false,
        public readonly ?string $appendNotificationUrl = null,
        public readonly ?string $overrideNotificationUrl = null,
        public readonly ?string $paymentLocale = null,
        public readonly ?string $popId = null,
    ) {
        if (strlen($this->idempotencyKeyPrefix) > IdempotencyKey::maxPrefixLength()) {
            throw new MidtransException(sprintf(
                'idempotencyKeyPrefix must be at most %d characters so the generated key stays '
                .'within the %d-character limit Midtrans enforces.',
                IdempotencyKey::maxPrefixLength(),
                IdempotencyKey::MAX_LENGTH,
            ));
        }

        foreach ([
            'coreBaseUrlOverride' => $this->coreBaseUrlOverride,
            'snapBaseUrlOverride' => $this->snapBaseUrlOverride,
            'snapBiBaseUrlOverride' => $this->snapBiBaseUrlOverride,
        ] as $name => $override) {
            $this->assertUsableBaseUrl($name, $override);
        }
    }

    private function assertUsableBaseUrl(string $name, ?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && $this->allowInsecureBaseUrl) {
            return;
        }

        throw new MidtransException(sprintf(
            '%s must use https (got %s). Every request carries the server key in an '
            .'Authorization header; set allowInsecureBaseUrl: true only to reach a local mock.',
            $name,
            $scheme === '' ? 'no scheme' : $scheme,
        ));
    }

    /**
     * Snap Preference lives on the v3 host while checkout stays on v1.
     */
    public function snapV3BaseUrl(): string
    {
        return (string) preg_replace('#/v1$#', '/v3', $this->snapBaseUrl());
    }

    public function coreBaseUrl(): string
    {
        if ($this->coreBaseUrlOverride !== null && $this->coreBaseUrlOverride !== '') {
            return rtrim($this->coreBaseUrlOverride, '/');
        }

        return $this->isProduction
            ? 'https://api.midtrans.com'
            : 'https://api.sandbox.midtrans.com';
    }

    public function snapBaseUrl(): string
    {
        if ($this->snapBaseUrlOverride !== null && $this->snapBaseUrlOverride !== '') {
            return rtrim($this->snapBaseUrlOverride, '/');
        }

        return $this->isProduction
            ? 'https://app.midtrans.com/snap/v1'
            : 'https://app.sandbox.midtrans.com/snap/v1';
    }

    /**
     * Snap-BI is served from its own host, not the Core API one.
     *
     * Pointing these paths at api.midtrans.com answers 404 with an empty body,
     * which surfaces as an unparseable response rather than as a wrong host.
     *
     * The Get Auth Code API is the one exception, living on merchants-app.*;
     * this SDK does not expose it, so it needs no separate base URL.
     *
     * @see https://docs.midtrans.com/reference/getting-started-1
     */
    public function snapBiBaseUrl(): string
    {
        if ($this->snapBiBaseUrlOverride !== null && $this->snapBiBaseUrlOverride !== '') {
            return rtrim($this->snapBiBaseUrlOverride, '/');
        }

        return $this->isProduction
            ? 'https://merchants.midtrans.com'
            : 'https://merchants.sbx.midtrans.com';
    }

    /**
     * Keeps credentials out of var_dump() and of dumpers built on Symfony
     * VarDumper, such as Laravel's dd()/dump().
     *
     * Note: print_r() and var_export() bypass __debugInfo() by design in PHP, so
     * never dump this object with those.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'serverKey' => self::mask($this->serverKey),
            'clientKey' => self::mask($this->clientKey),
            'isProduction' => $this->isProduction,
            'timeoutSeconds' => $this->timeoutSeconds,
            'maxRetries' => $this->maxRetries,
            'retryDelayMs' => $this->retryDelayMs,
            'idempotencyKeyPrefix' => $this->idempotencyKeyPrefix,
            'snapBiClientId' => self::mask($this->snapBiClientId),
            'snapBiPrivateKey' => $this->snapBiPrivateKey === null ? null : '[redacted]',
            'snapBiClientSecret' => self::mask($this->snapBiClientSecret),
            'snapBiPartnerId' => $this->snapBiPartnerId,
            'snapBiChannelId' => $this->snapBiChannelId,
            'snapBiDeviceId' => $this->snapBiDeviceId,
            'coreBaseUrlOverride' => $this->coreBaseUrlOverride,
            'snapBaseUrlOverride' => $this->snapBaseUrlOverride,
            'snapBiBaseUrlOverride' => $this->snapBiBaseUrlOverride,
            'allowInsecureBaseUrl' => $this->allowInsecureBaseUrl,
            'appendNotificationUrl' => $this->appendNotificationUrl,
            'overrideNotificationUrl' => $this->overrideNotificationUrl,
            'paymentLocale' => $this->paymentLocale,
            'popId' => $this->popId,
        ];
    }

    private static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return strlen($value) <= 8
            ? '[redacted]'
            : MidtransException::truncateUtf8($value, 4).'…[redacted]';
    }
}
