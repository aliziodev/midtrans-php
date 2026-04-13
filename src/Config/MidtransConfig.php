<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Config;

final class MidtransConfig
{
    public function __construct(
        public readonly string $serverKey,
        public readonly ?string $clientKey = null,
        public readonly bool $isProduction = false,
        public readonly int $timeoutSeconds = 30,
        public readonly int $maxRetries = 2,
        public readonly int $retryDelayMs = 200,
        public readonly ?string $defaultIdempotencyKey = null,
        public readonly ?string $snapBiClientId = null,
        public readonly ?string $snapBiPrivateKey = null,
        public readonly ?string $snapBiClientSecret = null,
        public readonly ?string $snapBiPartnerId = null,
        public readonly string $snapBiChannelId = '95221',
        public readonly ?string $snapBiDeviceId = null,
        public readonly ?string $coreBaseUrlOverride = null,
        public readonly ?string $snapBaseUrlOverride = null,
        public readonly ?string $snapBiBaseUrlOverride = null,
    ) {}

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

    public function snapBiBaseUrl(): string
    {
        if ($this->snapBiBaseUrlOverride !== null && $this->snapBiBaseUrlOverride !== '') {
            return rtrim($this->snapBiBaseUrlOverride, '/');
        }

        return $this->coreBaseUrl();
    }
}
