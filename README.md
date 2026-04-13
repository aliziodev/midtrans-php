# aliziodev/midtrans-php

[![CI](https://github.com/aliziodev/midtrans-php/actions/workflows/ci.yml/badge.svg)](https://github.com/aliziodev/midtrans-php/actions/workflows/ci.yml)
[![Release](https://github.com/aliziodev/midtrans-php/actions/workflows/release.yml/badge.svg)](https://github.com/aliziodev/midtrans-php/actions/workflows/release.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/aliziodev/midtrans-php.svg)](https://packagist.org/packages/aliziodev/midtrans-php)
[![PHP Version](https://img.shields.io/packagist/php-v/aliziodev/midtrans-php)](https://packagist.org/packages/aliziodev/midtrans-php)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Unofficial PHP SDK untuk Midtrans API.

Package ini dibuat sebagai SDK PHP murni (tanpa ketergantungan framework) untuk dipakai sebagai fondasi lintas project, termasuk adapter Laravel seperti payid-midtrans atau charter-midtrans.

## Ringkasnya

- Bahasa: PHP 8.2+
- Framework dependency: none
- Fokus: API wrapper yang jelas, aman dipakai production, dan mudah di-extend
- Cocok untuk: service payment internal, package billing, arsitektur modular multi-repo

## Kenapa Package Ini

- Menghindari duplikasi logic Midtrans di banyak project.
- Menjaga boundary domain lebih bersih (SDK core terpisah dari adapter framework).
- Menyediakan hardening yang relevan untuk production:
  - retry terkontrol
  - guard idempotency untuk request mutasi
  - utility verifikasi webhook
  - endpoint override untuk antisipasi perubahan host/path

## Instalasi

```bash
composer require aliziodev/midtrans-php
```

## Quick Start

```php
<?php

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\MidtransClient;

$config = new MidtransConfig(
    serverKey: 'SB-Mid-server-xxxx',
    isProduction: false,
);

$client = new MidtransClient($config);

$snap = $client->snapCreateTransaction([
    'transaction_details' => [
        'order_id' => 'ORDER-1001',
        'gross_amount' => 10000,
    ],
]);

$status = $client->transactionStatus('ORDER-1001');
```

## Perbandingan Dengan SDK Official

Referensi official: https://github.com/Midtrans/midtrans-php

Catatan konteks:
- Perbandingan ini berdasarkan audit implementasi repo official per 13 April 2026.
- Tujuan section ini bukan mengganti official SDK, tapi menjelaskan posisi package ini sebagai alternatif architecture-friendly.

### Ringkasan Posisi

- Untuk endpoint inti yang umum dipakai, coverage setara.
- Package ini menambahkan beberapa layer operational safety by default.
- Package ini juga menyediakan wrapper untuk beberapa API tambahan yang belum jadi helper dedicated di official.

### Matrix Singkat

| Area | midtrans/midtrans-php (Official) | aliziodev/midtrans-php | Status |
|---|---|---|---|
| Snap create transaction | ✅ | ✅ | Setara |
| Core charge/capture/status/refund lifecycle | ✅ | ✅ | Setara |
| Subscription lifecycle | ✅ | ✅ | Setara |
| Snap-BI (Direct Debit/VA/QRIS) | ✅ | ✅ | Setara |
| Konfigurasi style | Static global config | Object config per client | Berbeda desain |
| Retry policy | ❌ | ✅ (`maxRetries`, `retryDelayMs`) | Lebih kuat |
| Guard idempotency saat retry mutasi | ❌ | ✅ (wajib `Idempotency-Key`) | Lebih aman |
| Payment Link wrapper dedicated | ❌ | ✅ | Lebih lengkap |
| Balance Mutation wrapper dedicated | ❌ | ✅ | Lebih lengkap |
| Invoicing wrapper dedicated | ❌ | ✅ | Lebih lengkap |
| Utility webhook verifier classic SHA512 | ❌ (umumnya di app layer) | ✅ | Lebih siap pakai |

### Kapan Pilih Yang Mana

Pilih official jika:
- butuh mengikuti implementasi resmi 1:1,
- tim sudah terbiasa dengan static global config official.

Pilih package ini jika:
- butuh SDK netral untuk banyak package/app,
- ingin kontrol konfigurasi per instance,
- ingin hardening retry + idempotency di level SDK.

## Penggunaan Per Fitur

Bagian ini fokus ke pola pakai paling umum untuk tiap domain API.

### 1) Konfigurasi Dasar

```php
<?php

use Aliziodev\MidtransPhp\Config\MidtransConfig;

$config = new MidtransConfig(
    serverKey: 'SB-Mid-server-xxxx',
    clientKey: 'SB-Mid-client-xxxx', // opsional, dibutuhkan untuk endpoint kartu tertentu
    isProduction: false,
    timeoutSeconds: 30,
    maxRetries: 2,
    retryDelayMs: 300,
);
```

### 2) Endpoint Override (Future-Proof)

Gunakan ini jika endpoint diproxy internal, gateway berubah, atau ada kebutuhan routing khusus.

```php
<?php

$config = new MidtransConfig(
    serverKey: 'SB-Mid-server-xxxx',
    isProduction: false,
    coreBaseUrlOverride: 'https://api.sandbox.midtrans.com',
    snapBaseUrlOverride: 'https://app.sandbox.midtrans.com/snap/v1',
    snapBiBaseUrlOverride: 'https://api.sandbox.midtrans.com',
);
```

### 3) Snap API

```php
<?php

$client = new MidtransClient($config);

$transaction = $client->snapCreateTransaction([
    'transaction_details' => [
        'order_id' => 'SNAP-1001',
        'gross_amount' => 10000,
    ],
]);

$token = $client->getSnapToken([
    'transaction_details' => [
        'order_id' => 'SNAP-1002',
        'gross_amount' => 12000,
    ],
]);

$url = $client->getSnapUrl([
    'transaction_details' => [
        'order_id' => 'SNAP-1003',
        'gross_amount' => 15000,
    ],
]);
```

### 4) Core API v2

```php
<?php

$client = (new MidtransClient($config))
    ->withIdempotencyKey('idem-charge-1001');

$charge = $client->coreCharge([
    'payment_type' => 'gopay',
    'transaction_details' => [
        'order_id' => 'CORE-1001',
        'gross_amount' => 20000,
    ],
]);

$status = $client->transactionStatus('CORE-1001');
$cancel = $client->cancelTransaction('CORE-1001');
```

Endpoint core lain yang tersedia:
- capture
- approve/deny/expire
- refund + refund direct
- pay account link/get/unbind
- card register/token/point inquiry

### 5) Subscription API v1

```php
<?php

$client = (new MidtransClient($config))
    ->withIdempotencyKey('idem-subscription-1001');

$created = $client->createSubscription([
    'name' => 'Monthly Plan',
    'amount' => '10000',
    'currency' => 'IDR',
    'payment_type' => 'credit_card',
    'token' => 'token-xxxx',
    'schedule' => [
        'interval' => 1,
        'interval_unit' => 'month',
        'max_interval' => 12,
        'start_time' => '2026-04-13 10:00:00 +0700',
    ],
]);

$detail = $client->getSubscription('SUBSCRIPTION-ID');
$updated = $client->updateSubscription('SUBSCRIPTION-ID', ['name' => 'Monthly Pro']);
$disabled = $client->disableSubscription('SUBSCRIPTION-ID');
$enabled = $client->enableSubscription('SUBSCRIPTION-ID');
$canceled = $client->cancelSubscription('SUBSCRIPTION-ID');
```

### 6) Payment Link API

```php
<?php

$client = (new MidtransClient($config))
    ->withIdempotencyKey('idem-plink-1001');

$create = $client->createPaymentLink([
    'transaction_details' => [
        'order_id' => 'PLINK-1001',
        'gross_amount' => 150000,
    ],
    'usage_limit' => 1,
]);

$detail = $client->getPaymentLinkDetails('PLINK-1001');
$delete = $client->deletePaymentLink('PLINK-1001');
```

### 7) Balance Mutation API

```php
<?php

$mutation = $client->getBalanceMutation(
    currency: 'IDR',
    startTime: '2026-03-02T00:00:00+07:00',
    endTime: '2026-03-16T23:59:59+07:00',
);
```

### 8) Invoicing API

```php
<?php

$client = (new MidtransClient($config))
    ->withIdempotencyKey('idem-invoice-1001');

$invoice = $client->createInvoice([
    'order_id' => 'INV-ORDER-001',
    'invoice_number' => 'INV-001',
    'due_date' => '2026-05-01 10:00:00 +0700',
    'invoice_date' => '2026-04-01 10:00:00 +0700',
    'payment_type' => 'payment_link',
    'customer_details' => [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '08123456789',
    ],
    'item_details' => [
        [
            'item_id' => 'SKU-1',
            'description' => 'Sample Item',
            'quantity' => 1,
            'price' => 150000,
        ],
    ],
]);

$detail = $client->getInvoice($invoice['id']);
$void = $client->voidInvoice($invoice['id']);
```

### 9) Snap-BI

```php
<?php

use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;

$config = new MidtransConfig(
    serverKey: 'SB-Mid-server-xxxx',
    isProduction: false,
    snapBiClientId: 'your-client-id',
    snapBiPrivateKey: "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----",
    snapBiClientSecret: 'your-client-secret',
    snapBiPartnerId: 'your-partner-id',
);

$snapBi = new SnapBiClient($config);

$createDebit = $snapBi->createDirectDebit(
    payload: [
        'partnerReferenceNo' => 'REF-1001',
        'amount' => [
            'value' => '10000.00',
            'currency' => 'IDR',
        ],
    ],
    externalId: '1001',
);
```

Method Snap-BI yang tersedia:
- create/status/cancel/refund untuk Direct Debit
- create/status/cancel untuk VA
- create/status/cancel/refund untuk QRIS

### 10) Webhook Verification

Classic Midtrans signature SHA512:

```php
<?php

use Aliziodev\MidtransPhp\Webhooks\MidtransSignatureVerifier;

$isValid = MidtransSignatureVerifier::verify($payload, $serverKey);
```

Snap-BI webhook RSA SHA256:

```php
<?php

use Aliziodev\MidtransPhp\Webhooks\SnapBiWebhookVerifier;

$isValid = SnapBiWebhookVerifier::verify(
    body: $payload,
    signature: $xSignature,
    timestamp: $xTimestamp,
    notificationUrlPath: '/v1.0/debit/notify',
    publicKey: $snapBiPublicKey,
);
```

## Error Handling

Semua kegagalan API akan dilempar sebagai `MidtransApiException`.
Kegagalan SDK/transport akan dilempar sebagai `MidtransException`.

```php
<?php

use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;

try {
    $result = $client->createPaymentLink([
        'transaction_details' => [
            'order_id' => 'PLINK-ERR-1',
            'gross_amount' => 10000,
        ],
    ]);
} catch (MidtransApiException $e) {
    // API error 4xx/5xx dari Midtrans
    $statusCode = $e->statusCode;
    $payload = $e->payload;

    if ($statusCode === 409) {
        // contoh: duplicate order_id
    }
} catch (MidtransException $e) {
    // transport/config/response parsing error
}
```

## Retry Dan Idempotency

Aturan penting:
- Jika `maxRetries > 0`, semua request non-GET wajib punya `Idempotency-Key`.
- Ini untuk mencegah duplicate mutation saat retry.

```php
<?php

use Aliziodev\MidtransPhp\Support\IdempotencyKey;

$config = new MidtransConfig(
    serverKey: 'Mid-server-prod-xxxx',
    isProduction: true,
    timeoutSeconds: 30,
    maxRetries: 2,
    retryDelayMs: 300,
);

$client = (new MidtransClient($config))
    ->withIdempotencyKey(IdempotencyKey::generate('invoice-create'));
```

## Testing

```bash
composer test:unit
composer test:integration
composer analyse
composer qa
```

## Roadmap

- Typed exception per kategori error
- Optional PSR-18 transport adapter
- Integrasi test yang lebih luas untuk skenario sandbox

## Catatan

Package ini menggunakan SDK official sebagai referensi kompatibilitas, tetapi tidak menjadi runtime dependency.