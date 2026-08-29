# Unofficial PHP SDK Midtrans API untuk PHP

[![CI](https://github.com/aliziodev/midtrans-php/actions/workflows/ci.yml/badge.svg)](https://github.com/aliziodev/midtrans-php/actions/workflows/ci.yml)
[![Release](https://github.com/aliziodev/midtrans-php/actions/workflows/release.yml/badge.svg)](https://github.com/aliziodev/midtrans-php/actions/workflows/release.yml)
[![codecov](https://codecov.io/gh/aliziodev/midtrans-php/graph/badge.svg)](https://codecov.io/gh/aliziodev/midtrans-php)
[![Latest Stable Version](https://img.shields.io/packagist/v/aliziodev/midtrans-php.svg)](https://packagist.org/packages/aliziodev/midtrans-php)
[![Total Downloads](https://img.shields.io/packagist/dt/aliziodev/midtrans-php)](https://packagist.org/packages/aliziodev/midtrans-php)
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
  - retry hanya untuk request yang terbukti aman diulang
  - `Idempotency-Key` di-generate per-operasi, bukan satu key statis
  - exponential backoff + jitter, dan menghormati `Retry-After`
  - utility verifikasi webhook (termasuk jendela anti-replay)
  - endpoint override yang divalidasi harus https

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

$snap = $client->createSnapTransaction([
    'transaction_details' => [
        'order_id' => 'ORDER-1001',
        'gross_amount' => 10000,
    ],
]);

$status = $client->getTransactionStatus('ORDER-1001');
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
| Retry policy | ❌ | ✅ (backoff + jitter, `Retry-After`) | Lebih kuat |
| Idempotency-Key per-operasi | ❌ | ✅ (auto-generate, divalidasi ≤46 char) | Lebih aman |
| Retry hanya untuk operasi yang aman diulang | ❌ | ✅ | Lebih aman |
| Cek `status_code` di body respons 2xx | ✅ | ✅ | Setara |
| HTTP 202 tidak dianggap hasil final | ❌ | ✅ (`MidtransPendingException`) | Lebih aman |
| Payment Link wrapper dedicated | ❌ | ✅ | Lebih lengkap |
| Balance Mutation wrapper dedicated | ❌ | ✅ | Lebih lengkap |
| Invoicing wrapper dedicated | ❌ | ✅ | Lebih lengkap |
| Utility webhook verifier classic SHA512 | ❌ (umumnya di app layer) | ✅ | Lebih siap pakai |
| Verifikasi webhook Snap-BI dari raw body | ❌ | ✅ | Lebih siap pakai |
| Cache access token Snap-BI | ❌ | ✅ | Lebih hemat |

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

    // Prefix untuk Idempotency-Key yang di-generate per request.
    // Maksimal 13 karakter (batas Midtrans 46 char - 33 char suffix acak).
    idempotencyKeyPrefix: 'shop',

    // Header opsional (lihat docs Midtrans "API Headers & Idempotency")
    appendNotificationUrl: null,
    overrideNotificationUrl: null,
    paymentLocale: null, // 'id-ID' | 'en-EN'
    popId: null,
);
```

Objek config aman untuk `var_dump()` dan `dd()` — semua kredensial dimasking lewat
`__debugInfo()`. Catatan: `print_r()` dan `var_export()` memang mengabaikan
`__debugInfo()` di PHP, jadi jangan pakai keduanya untuk dump config.

### 2) Endpoint Override (Future-Proof)

Gunakan ini jika endpoint diproxy internal, gateway berubah, atau ada kebutuhan routing khusus.

Semua override **wajib https**. Setiap request membawa server key di header
`Authorization`, jadi override berskema `http://` ditolak kecuali kamu secara
eksplisit menyalakan `allowInsecureBaseUrl: true` (hanya untuk mock lokal).

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

$transaction = $client->createSnapTransaction([
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

$charge = $client->chargeTransaction([
    'payment_type' => 'gopay',
    'transaction_details' => [
        'order_id' => 'CORE-1001',
        'gross_amount' => 20000,
    ],
]);

$status = $client->getTransactionStatus('CORE-1001');
$cancel = $client->cancelTransaction('CORE-1001');
```

Endpoint core lain yang tersedia:
- capture
- approve/deny/expire
- refund + refund direct
- pay account link/get/unbind
- point inquiry
- `getBin()` — BIN API
- `getGopayPromotions()` — promo GoPay Tokenization
- `cancelSnapSession()` — batalkan halaman Snap sebelum expiry
- `getSnapPreferences()` / `updateSnapPreferences()` — Snap Preference API v3

> **Refund bisa ditolak.** Sejak 16 Maret 2026 skema kartu mewajibkan otorisasi
> real-time ke issuing bank untuk refund, jadi request refund bisa berstatus
> `deny` sama seperti charge. Tangani status itu, jangan asumsikan refund selalu
> berhasil.

> **Tokenisasi kartu: jangan dari server.** `getCardToken()` dan `registerCard()`
> ditandai `@deprecated` sejak 2.0.0. Keduanya menaruh nomor kartu (dan CVV) di
> query string URL dan membuat server kamu menyentuh data kartu mentah — itu
> menarik aplikasi ke scope PCI-DSS SAQ D, dan URL tercatat di log web server,
> proxy, dan APM. Midtrans mendokumentasikan tokenisasi sebagai flow browser:
> muat `midtrans-new-3ds.min.js` dengan client key, panggil
> `MidtransNew3ds.getCardToken()`, lalu kirim `token_id`-nya saja ke backend.

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

// Konversi quotation menjadi invoice (hanya document_type = quotation,
// belum expired, dan belum pernah dikonversi).
$converted = $client->convertInvoice($invoice['id'], [
    'client' => ['email' => 'john@example.com'],
]);
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
- `bindAccount()` / `unbindAccount()` / `getAccountBindingStatus()` — account linking
- `captureAuthorization()` / `voidAuthorization()` — pre-authorization
- `getTransactionHistoryList()` / `getTransactionHistoryDetail()` — reporting

`X-EXTERNAL-ID` adalah satu-satunya proteksi replay yang ditawarkan Snap-BI
(unik per request, TTL 24 jam). Pakai `ExternalId::generate()` untuk membuatnya,
dan gunakan ulang nilai yang sama hanya saat mengulang operasi yang sama:

```php
<?php

use Aliziodev\MidtransPhp\SnapBi\ExternalId;

$externalId = ExternalId::generate();
$result = $snapBi->createQris($payload, $externalId);
```

Access token B2B di-cache otomatis selama masa berlakunya. Panggil
`$snapBi->clearAccessTokenCache()` kalau perlu memaksa token baru.

### 10) Webhook Verification

Classic Midtrans signature SHA512 — pakai `verifyRaw()` dengan body mentah:

```php
<?php

use Aliziodev\MidtransPhp\Webhooks\MidtransSignatureVerifier;

// Laravel: $request->getContent()
$isValid = MidtransSignatureVerifier::verifyRaw($rawBody, $serverKey);
```

`verifyRaw()` lebih aman daripada `verify($array, ...)` karena signature dihitung
dari `gross_amount` sebagai string persis yang dikirim Midtrans (`"10000.00"`).
Framework atau mapper yang meng-cast nilai itu ke float mengubahnya jadi
`"10000"` dan verifikasi gagal.

Snap-BI webhook RSA SHA256 — **wajib** body mentah:

```php
<?php

use Aliziodev\MidtransPhp\Webhooks\SnapBiWebhookVerifier;

$isValid = SnapBiWebhookVerifier::verify(
    rawBody: $request->getContent(),
    signature: $xSignature,
    timestamp: $xTimestamp,
    notificationUrlPath: '/v1.0/debit/notify',
    publicKey: $snapBiPublicKey,
    // toleransi X-TIMESTAMP, default 300 detik; null untuk mematikan
    toleranceSeconds: 300,
);
```

Signature dihitung dari `sha256(minify(rawBody))`. Array hasil `json_decode()`
yang di-encode ulang tidak pernah mereproduksi byte aslinya — `{}` kosong
berubah jadi `[]` — sehingga notifikasi yang sah akan ditolak.

> Signature valid membuktikan keaslian, bukan kebaruan. Notifikasi asli tetap
> bisa di-replay. Selalu cek ulang transaksi lewat
> `MidtransClient::getTransactionStatus()` sebelum melepas barang atau layanan.

## Error Handling

| Exception | Kapan dilempar |
|---|---|
| `MidtransApiException` | Midtrans menolak request — HTTP 4xx/5xx, atau HTTP 2xx dengan `status_code`/`responseCode` error di body |
| `MidtransPendingException` | HTTP 202: request sebelumnya dengan Idempotency-Key yang sama masih diproses. **Bukan** hasil final |
| `MidtransException` | Kegagalan transport, config, atau parsing respons |

`MidtransPendingException` dan `MidtransApiException` sama-sama turunan
`MidtransException`, jadi tangkap yang spesifik lebih dulu.

```php
<?php

use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Exceptions\MidtransPendingException;

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
} catch (MidtransPendingException $e) {
    // HTTP 202 - ulangi request yang sama dengan key yang sama untuk hasil final
} catch (MidtransException $e) {
    // transport/config/response parsing error
}
```

Pesan pada `MidtransException` sudah dipotong dan diredaksi (rangkaian digit
sepanjang nomor kartu diganti `[redacted]`), jadi body respons tidak bocor utuh
ke log.

## Retry Dan Idempotency

Midtrans men-cache respons pertama untuk sebuah `Idempotency-Key` selama **5 menit**
dan mengembalikannya untuk request berikutnya yang memakai key yang sama —
**terlepas dari isi body, dan lintas endpoint**. Karena itu SDK ini
**men-generate key baru untuk setiap operasi mutasi**, bukan memakai satu key
statis dari config.

```php
<?php

$config = new MidtransConfig(
    serverKey: 'Mid-server-prod-xxxx',
    isProduction: true,
    timeoutSeconds: 30,
    maxRetries: 2,
    retryDelayMs: 300,
    idempotencyKeyPrefix: 'shop', // maksimal 13 karakter
);

$client = new MidtransClient($config);

// Setiap charge di bawah ini mendapat Idempotency-Key sendiri.
$client->chargeTransaction($orderA);
$client->chargeTransaction($orderB);
```

Kalau kamu perlu mengontrol key sendiri — misalnya untuk mengulang operasi yang
sama persis setelah timeout — gunakan `withIdempotencyKey()`. Satu key untuk satu
operasi:

```php
<?php

use Aliziodev\MidtransPhp\Support\IdempotencyKey;

$key = IdempotencyKey::generate('charge'); // simpan bersama order-nya

$client = (new MidtransClient($config))->withIdempotencyKey($key);
```

Panjang key dibatasi **46 karakter**; Midtrans mengabaikan key yang lebih panjang
tanpa memberi tahu, jadi SDK menolaknya lebih dulu daripada membiarkan proteksi
retry mati diam-diam.

### Kapan request diulang

| Request | Diulang? | Alasan |
|---|---|---|
| `GET` apa pun | ✅ | Tidak mengubah state |
| `POST` yang menerima `Idempotency-Key` | ✅ | Midtrans memutar ulang respons pertama |
| `PATCH` / `DELETE` (void, delete, convert) | ✅ | Hanya mendorong state terminal |
| `POST /v2/token`, `/v2/card/register`, `/v2/pay/account*` | ❌ | Midtrans tidak menerima `Idempotency-Key` di sini, jadi tidak ada proteksi replay |

Retry memakai exponential backoff dengan jitter penuh, dan menghormati header
`Retry-After` pada respons 429.

### Refund

`Idempotency-Key` hanya melindungi jendela 5 menit. Proteksi sebenarnya untuk
refund adalah `refund_key`: tanpa itu Midtrans memperlakukan setiap request
sebagai refund baru. Karena itu `refundTransaction()` dan
`refundTransactionDirect()` menolak payload tanpa `refund_key` selama
`maxRetries > 0`.

```php
<?php

$client->refundTransaction('ORDER-1001', [
    'refund_key' => 'refund-ORDER-1001-1', // stabil untuk satu refund
    'amount' => 10000,
    'reason' => 'out of stock',
]);
```

## Migrasi 1.x ke 2.0

### Penamaan method

Sebagian method tidak mengikuti konvensi verb-first yang dipakai sisa class
(`createInvoice`, `getSubscription`, `deletePaymentLink`). Di 2.0 semuanya
diseragamkan. Tidak ada alias — nama lama hilang, sehingga pemakaian yang
tertinggal gagal keras di tempatnya, bukan diam-diam.

| 1.x | 2.0 |
|---|---|
| `snapCreateTransaction()` | `createSnapTransaction()` |
| `coreCharge()` | `chargeTransaction()` |
| `transactionStatus()` | `getTransactionStatus()` |
| `transactionStatusB2b()` | `getTransactionStatusB2b()` |
| `cardRegister()` | `registerCard()` |
| `cardToken()` | `getCardToken()` |
| `cardPointInquiry()` | `getCardPointInquiry()` |

`SnapBiClient` kena hal yang sama — `createDirectDebit()` berdampingan dengan
`directDebitStatus()`:

| 1.x | 2.0 |
|---|---|
| `directDebitStatus()` | `getDirectDebitStatus()` |
| `vaStatus()` | `getVaStatus()` |
| `qrisStatus()` | `getQrisStatus()` |
| `directDebitCancel()` | `cancelDirectDebit()` |
| `vaCancel()` | `cancelVa()` |
| `qrisCancel()` | `cancelQris()` |
| `directDebitRefund()` | `refundDirectDebit()` |
| `qrisRefund()` | `refundQris()` |

Cari sisa pemakaian dengan:

```bash
grep -rnE '\->(snapCreateTransaction|coreCharge|transactionStatusB2b|transactionStatus|cardRegister|cardToken|cardPointInquiry|directDebitStatus|vaStatus|qrisStatus|directDebitCancel|vaCancel|qrisCancel|directDebitRefund|qrisRefund)\(' app src
```

### Perubahan perilaku

| Perubahan | Aksi |
|---|---|
| `MidtransConfig::$defaultIdempotencyKey` dihapus | Hapus argumennya. Kalau butuh prefix, pakai `idempotencyKeyPrefix` (maks. 13 karakter). Key kini di-generate per request |
| `SnapBiWebhookVerifier::verify()` menerima `rawBody: string`, bukan `body: array` | Kirim body mentah (`$request->getContent()`), bukan array hasil decode |
| Override base URL wajib `https` | Perbaiki URL, atau set `allowInsecureBaseUrl: true` untuk mock lokal |
| Refund tanpa `refund_key` melempar exception saat retry aktif | Tambahkan `refund_key` yang stabil, atau set `maxRetries: 0` |
| HTTP 202 kini melempar `MidtransPendingException` | Tangkap exception itu dan ulangi dengan key yang sama |
| Respons 2xx dengan `status_code` ≥ 401 (atau `responseCode` non-2xx di Snap-BI) kini melempar `MidtransApiException` | Hilangkan pengecekan `status_code` manual di sisi aplikasi |
| `getCardToken()` / `registerCard()` `@deprecated` | Pindah ke tokenisasi sisi browser |
| Transport error pada percobaan terakhir melempar `MidtransException` alih-alih mengembalikan respons 5xx dari percobaan sebelumnya | Tangkap `MidtransException` |

`HttpResponse` sekarang punya parameter ketiga opsional `$headers`; implementasi
`Transport` kustom tetap kompatibel tanpa perubahan.

## Testing

```bash
composer test:unit         # semua unit test
composer test:integration  # smoke test sandbox, butuh MIDTRANS_SMOKE_TEST=1
composer test:coverage     # butuh pcov atau xdebug terpasang
composer analyse
composer qa
```

`CurlTransportServerTest` menjalankan `CurlTransport` terhadap server bawaan PHP
di `127.0.0.1`, karena loop retry hanya benar-benar terbukti lewat socket asli —
jumlah percobaan, `Retry-After` yang ditunggu, capture header, dan redirect yang
tidak diikuti. Konsekuensinya suite jadi ~6 detik, bukan ~0,06 detik. Kalau
sedang iterasi cepat, lewati saja:

```bash
vendor/bin/phpunit --testsuite unit --exclude-group transport
```

Coverage dilaporkan ke Codecov pada tiap push. Butuh secret `CODECOV_TOKEN` di
repository settings.

## Roadmap

- Optional PSR-18 transport adapter
- PSR-3 logger hook untuk audit trail request/response
- Integrasi test yang lebih luas untuk skenario sandbox
- Hapus `getCardToken()` / `registerCard()` di 3.0.0

## Catatan

Package ini menggunakan SDK official sebagai referensi kompatibilitas, tetapi tidak menjadi runtime dependency.