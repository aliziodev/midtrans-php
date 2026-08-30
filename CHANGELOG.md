## [2.0.3](https://github.com/aliziodev/midtrans-php/compare/v2.0.2...v2.0.3) (2026-08-30)

### Bug Fixes

* convert an invoice with POST, and cover the Core API business flows ([63c78b4](https://github.com/aliziodev/midtrans-php/commit/63c78b43e12dde66ce689957977eb097826b4cfd))

## [2.0.2](https://github.com/aliziodev/midtrans-php/compare/v2.0.1...v2.0.2) (2026-08-30)

### Bug Fixes

* report an unreadable error response by its HTTP status ([b0388d1](https://github.com/aliziodev/midtrans-php/commit/b0388d1cabefa86ec153ef70e2a35517109130f2))

## [2.0.1](https://github.com/aliziodev/midtrans-php/compare/v2.0.0...v2.0.1) (2026-08-30)

### Bug Fixes

* **snap-bi:** point Snap-BI at its own host ([c234a3a](https://github.com/aliziodev/midtrans-php/commit/c234a3a6beb39199c1e123e93ea45462fc27d5fc))

## [2.0.0](https://github.com/aliziodev/midtrans-php/compare/v1.0.1...v2.0.0) (2026-08-29)

### ⚠ BREAKING CHANGES

* **snap-bi:** eight SnapBiClient methods are renamed with no backwards
compatible aliases. See the "Penamaan method" table in the README.
* **client:** seven MidtransClient methods are renamed with no backwards
compatible aliases. See the "Penamaan method" table in the README for the
mapping.
* **snap-bi:** an empty X-EXTERNAL-ID now throws, and a Snap-BI response
carrying a non-2xx responseCode raises MidtransApiException instead of being
returned as a successful array.
* **core:** MidtransConfig::$defaultIdempotencyKey is removed; use
idempotencyKeyPrefix, capped at 13 characters. Plaintext http base URL
overrides are rejected. Refunds without refund_key throw while retries are
enabled. HTTP 202 and error status_code values in 2xx bodies now throw.
* **webhooks:** SnapBiWebhookVerifier::verify() now takes rawBody as a string
instead of body as an array. Pass the untouched request body, in Laravel
$request->getContent().
* **http:** when the final retry attempt fails at transport level,
CurlTransport now throws MidtransException instead of returning a stale 5xx
response captured on an earlier attempt. The outcome of that request is
genuinely unknown, and reporting the earlier response claimed otherwise.

### Features

* **core:** generate idempotency keys per operation and retry only when safe ([e30385f](https://github.com/aliziodev/midtrans-php/commit/e30385f660a2c73edcd4d1853d38b92143f27986))
* **http:** capture response headers, back off with jitter, honour Retry-After ([620236e](https://github.com/aliziodev/midtrans-php/commit/620236e8e23ddf0dbaacb44d524d6bacf1b3dfa7))
* **snap-bi:** guard external id, cache the access token, add missing endpoints ([f20c917](https://github.com/aliziodev/midtrans-php/commit/f20c917d440c38da5351c69ec973a6f87a0e9a58))
* **support:** reject idempotency keys Midtrans would ignore, redact error bodies ([b584cbf](https://github.com/aliziodev/midtrans-php/commit/b584cbf3e1b9bfe99d692aeb35baec0c26622636))

### Bug Fixes

* keep truncated messages valid UTF-8, and allow custom request headers ([bca78d2](https://github.com/aliziodev/midtrans-php/commit/bca78d2d91ec702970d536a9fb81fa9fe389c695)), closes [Midtrans/midtrans-php#91](https://github.com/Midtrans/midtrans-php/issues/91) [Midtrans/midtrans-php#113](https://github.com/Midtrans/midtrans-php/issues/113)
* **webhooks:** verify Snap-BI notifications against the raw request body ([7b9f59f](https://github.com/aliziodev/midtrans-php/commit/7b9f59fef5afa9b449312102cb6465f58866cfcd))

### Code Refactoring

* **client:** rename methods to the verb-first convention ([83363cb](https://github.com/aliziodev/midtrans-php/commit/83363cb71d83e6817d595e788487003b26dbed9f))
* **snap-bi:** rename methods to the verb-first convention ([ebedc46](https://github.com/aliziodev/midtrans-php/commit/ebedc468d7ecfb34722a59982d3e9ea80149ab58))

## [1.0.1](https://github.com/aliziodev/midtrans-php/compare/v1.0.0...v1.0.1) (2026-04-13)

### Bug Fixes

* update README header and add total downloads badge ([008aa98](https://github.com/aliziodev/midtrans-php/commit/008aa98f70f233f14b1546d8a1cf3e06f181b62e))

## 1.0.0 (2026-04-13)

### Bug Fixes

* update content-hash in composer.lock ([d376077](https://github.com/aliziodev/midtrans-php/commit/d3760771e692ba41d5b1a415492d9ea66533e213))
