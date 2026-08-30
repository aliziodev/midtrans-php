---
name: midtrans-php
description: "Apply this skill whenever writing or reviewing PHP that calls the aliziodev/midtrans-php SDK directly. Triggers for creating Snap transactions, Core API charges, capture, status, cancel and refund, subscriptions, payment links, invoicing, Snap-BI / BI-SNAP direct debit, virtual accounts and QRIS, verifying webhook signatures, and for writing tests against the SDK. Also use when debugging a refund answered with 412, an HTTP 202 that is not a result, a signature that will not verify, an unreadable response body, or a charge or refund that happened twice after a retry. In a Laravel application prefer aliziodev/laravel-midtrans and its own skill; this one is for direct SDK use."
license: MIT
metadata:
  author: aliziodev
---

# Midtrans PHP SDK

Rules for `aliziodev/midtrans-php`, a framework-agnostic SDK for the Midtrans
API. It owns transport, retries, idempotency, error mapping and signature
verification. Request and response *shapes* belong to Midtrans — verify those
against the documentation rather than inventing fields.

In a Laravel application, install `aliziodev/laravel-midtrans` instead of wiring
this by hand: it builds the config from `config/midtrans.php`, registers a
verified webhook route and dispatches payment events. Reach for the SDK directly
only outside Laravel, or when you deliberately want no wrapper.

## Building the client

Build `MidtransConfig` once and hand it to `MidtransClient`. Both are immutable:
`withIdempotencyKey()` and `withHeaders()` return a **new** client, so a call
whose result is discarded does nothing.

```php
$config = new MidtransConfig(
    serverKey: getenv('MIDTRANS_SERVER_KEY'),
    isProduction: false,
);

$client = new MidtransClient($config);

// Wrong: the header is thrown away.
$client->withHeaders(['transaction-source' => 'SNAP_API']);

// Right.
$client = $client->withHeaders(['transaction-source' => 'SNAP_API']);
```

`Authorization` and `Idempotency-Key` are rejected by `withHeaders()` — they are
derived from the config, and overriding them either leaks the key or breaks the
retry guard. Use `withIdempotencyKey()` for the latter.

Method names are verb-first: `createSnapTransaction`, `chargeTransaction`,
`getTransactionStatus`, `cancelTransaction`, `refundTransaction`,
`getCardToken`. If you recall `snapCreateTransaction`, `coreCharge`,
`transactionStatus`, `cardToken` or `cardRegister`, those are 1.x names, removed
in 2.0 with no aliases.

## A call returns an array or it throws

Do not inspect `status_code` yourself. The SDK already throws when the body
reports a failure under an HTTP 2xx, which Midtrans does routinely.

```php
try {
    $charge = $client->chargeTransaction($payload);
} catch (MidtransPendingException $e) {
    // HTTP 202 — an earlier request with this key is still running.
} catch (MidtransApiException $e) {
    $e->statusCode; // from the HTTP status, or from the body's status_code
    $e->payload;    // decoded body
} catch (MidtransException $e) {
    // Transport, encoding or configuration failure.
}
```

`MidtransPendingException` is **not** a result. Retry the same request carrying
the same key — a fresh key starts a second charge:

```php
$result = $client->withIdempotencyKey($e->idempotencyKey)->chargeTransaction($payload);
```

A `MidtransException` from the transport means the outcome is *unknown*, not that
nothing happened: the request may have reached Midtrans and its response been
lost. Re-read with `getTransactionStatus()` before charging again.

## Idempotency

The client generates a fresh `Idempotency-Key` per mutating request. Never pass
one fixed key for everything: Midtrans replays a key's cached response for five
minutes across bodies *and* endpoints, so a shared key makes a charge for one
order return another order's response. Use `withIdempotencyKey()` only to repeat
one specific operation.

Keys are capped at 46 characters and `idempotencyKeyPrefix` at 13, because
Midtrans silently ignores a longer key — which would leave the retry guard
looking present and doing nothing.

`/v2/token`, `/v2/card/register` and `/v2/pay/account` do not accept the header,
so those POSTs are never retried: without server-side replay protection a retry
could create a second account binding.

## Refunds

`refundTransaction()` and `refundTransactionDirect()` require `refund_key` in the
payload while `maxRetries > 0`, and throw without one. Midtrans treats a refund
with no `refund_key` as a *new* refund, so a retried request refunds twice. Use a
value that is stable for that one refund.

A 412 covers three unrelated causes, indistinguishable by status code:

- the method cannot be refunded at all — bank transfer and over-the-counter
  never can;
- the transaction has not settled. A card charge sits in `capture` until the
  settlement batch runs, so use `cancelTransaction()` before then;
- refund is not activated on the merchant account. It is opt-in, and Midtrans
  enables it on request.

Since 16 March 2026 card schemes require real-time issuer authorisation, so an
accepted refund request can still come back denied. Read the resulting status
rather than assuming the money moved.

## Webhooks

Verify against the raw request body, never a re-encoded array:

```php
$raw = file_get_contents('php://input');

if (! MidtransSignatureVerifier::verifyRaw($raw, $serverKey)) {
    http_response_code(403);

    return;
}
```

The signature is computed over `gross_amount` as the exact text Midtrans sent
(`"10000.00"`). A framework or mapper that casts it to a float turns it into
`"10000"` and verification fails. Keep it a string everywhere; compare amounts
with `bccomp()`, or in minor units you derive yourself.

A valid signature proves authenticity, not freshness — a genuine notification can
be captured and replayed, and can be stale by the time it arrives. Always re-read
`getTransactionStatus()` before releasing goods, and act on that.

Only `settlement`, or `capture` with `fraud_status=accept`, means the money
arrived. `pending` is instructions issued, `authorize` is funds held on a card,
`deny` and `challenge` are fraud outcomes; none of them are paid.

Let a failing handler return 500. Midtrans redelivers, which is the behaviour you
want; swallowing the error loses the payment.

To point notifications at a tunnel without touching the dashboard, set
`overrideNotificationUrl` on the config. The SDK sends it as
`X-Override-Notification` with the charge, and Midtrans notifies that URL for that
transaction.

For Snap-BI, `SnapBiWebhookVerifier::verify()` needs the raw bytes too, plus the
path of your notification URL and the `X-TIMESTAMP` header. Re-encoding a decoded
array cannot reproduce those bytes — an empty JSON object comes back as `[]` and
the hash never matches. The timestamp tolerance is the only replay window on
offer; passing `toleranceSeconds: null` disables it.

## Snap-BI

Separate client, separate host, separate response shape. `SnapBiClient` talks to
`merchants(.sbx).midtrans.com`; pointing those paths at the Core API host answers
404 with an empty body, which surfaces as an unreadable response rather than as a
wrong host.

The outcome lives in `responseCode` — seven digits, a leading `2` means success —
not in `transaction_status`. The SDK throws `MidtransApiException` for a non-2xx
`responseCode` even under HTTP 200.

The B2B access token is cached **per client instance**, so keep one instance for
the request or worker instead of constructing one per call; otherwise every call
mints a token and burns rate limit.

`snapBiPrivateKey` is the PEM contents including the
`-----BEGIN PRIVATE KEY-----` header, not a path to the file.

Every authorized call takes an `$externalId`. It is the only replay protection
Snap-BI offers, with a 24-hour TTL: generate it with `ExternalId::generate()` and
reuse a value only when repeating the same logical operation.

Virtual accounts add two rules that both answer 400 without naming themselves:

- `partnerServiceId` is eight characters, right aligned and space padded, so
  `"12345"` is sent as `"   12345"`. `virtualAccountNo` is that followed by
  `customerNo`, padding included.
- `trxId` must equal the `X-EXTERNAL-ID` sent alongside it. Only this endpoint
  has that rule.

`getVaStatus()` needs `inquiryRequestId` set to the *creation* `trxId`, not a
fresh value — a new one answers 404 even for an account that has been paid. Keep
it, or read it back from `virtualAccountData.inquiryRequestId` in the create
response.

## Testing

Never let a test reach the network. `MidtransClient` and `SnapBiClient` both take
a `Transport` in the constructor, so implement one that records the request and
returns a canned `HttpResponse`, then assert on what was sent. Returning a 4xx
status — or a 200 whose body carries `"status_code": "404"` — exercises your error
handling exactly as the real API would.

## Do not

- Call `getCardToken()` or `registerCard()` from the server. Both are deprecated:
  they put the card number and CVV in a URL query string, which web servers,
  proxies and APM agents log, and they pull the application into PCI-DSS SAQ D
  scope. Tokenize in the browser with `midtrans-new-3ds.min.js` and send only the
  resulting `token_id` to the backend.
- Dump `MidtransConfig` with `print_r()` or `var_export()`. `__debugInfo()` masks
  the credentials, and PHP bypasses it for exactly those two functions.
- Set `allowInsecureBaseUrl: true` against anything but a local mock. Every
  request carries the server key in an `Authorization` header.
- Log a decoded response or webhook payload in full. It carries customer PII and,
  for card flows, masked card data. Exception messages are already redacted and
  truncated; the arrays handed back to you are not.
