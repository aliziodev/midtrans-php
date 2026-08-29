<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Exceptions\MidtransPendingException;
use Aliziodev\MidtransPhp\Http\HttpResponse;
use Aliziodev\MidtransPhp\MidtransClient;
use Aliziodev\MidtransPhp\Support\IdempotencyKey;
use Aliziodev\MidtransPhp\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class MidtransClientTest extends TestCase
{
    public function test_each_mutating_request_gets_its_own_generated_key(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 1, idempotencyKeyPrefix: 'shop'),
            transport: $transport,
        );

        $client->chargeTransaction(['transaction_details' => ['order_id' => 'ORDER-A', 'gross_amount' => 10000]]);
        $client->chargeTransaction(['transaction_details' => ['order_id' => 'ORDER-B', 'gross_amount' => 99999]]);

        $first = $transport->requests[0]['headers']['Idempotency-Key'];
        $second = $transport->requests[1]['headers']['Idempotency-Key'];

        self::assertStringStartsWith('shop-', $first);
        self::assertNotSame(
            $first,
            $second,
            'Reusing one key makes Midtrans replay the first response for the second order',
        );
        self::assertLessThanOrEqual(IdempotencyKey::MAX_LENGTH, strlen($first));
    }

    public function test_idempotency_key_is_withheld_where_midtrans_does_not_accept_it(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', clientKey: 'client-key', maxRetries: 2),
            transport: $transport,
        );

        $client->linkPaymentAccount(['payment_type' => 'gopay']);
        $client->unlinkPaymentAccount('acc-1');
        $client->getCardToken('4811111111111114', '12', '2029', '123');

        foreach ($transport->requests as $request) {
            self::assertArrayNotHasKey('Idempotency-Key', $request['headers'], $request['url']);
        }
    }

    public function test_requests_without_replay_protection_are_never_retried(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 3),
            transport: $transport,
        );

        $client->linkPaymentAccount(['payment_type' => 'gopay']);
        $client->chargeTransaction(['transaction_details' => ['order_id' => '1', 'gross_amount' => 10000]]);
        $client->getTransactionStatus('ORDER-1');

        self::assertSame(0, $transport->requests[0]['maxRetries'], '/v2/pay/account has no server-side replay guard');
        self::assertSame(3, $transport->requests[1]['maxRetries'], 'charge carries an Idempotency-Key');
        self::assertSame(3, $transport->requests[2]['maxRetries'], 'GET is always safe to retry');
    }

    public function test_explicit_idempotency_key_is_used_as_given(): void
    {
        $transport = new FakeTransport;
        $client = (new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 1),
            transport: $transport,
        ))->withIdempotencyKey('idem-123');

        $client->chargeTransaction(['transaction_details' => ['order_id' => '1', 'gross_amount' => 10000]]);

        self::assertSame('idem-123', $transport->requests[0]['headers']['Idempotency-Key']);
        self::assertSame('https://api.sandbox.midtrans.com/v2/charge', $transport->requests[0]['url']);
    }

    public function test_explicit_idempotency_key_that_midtrans_would_ignore_is_rejected(): void
    {
        $client = (new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 1),
            transport: new FakeTransport,
        ))->withIdempotencyKey(str_repeat('x', IdempotencyKey::MAX_LENGTH + 1));

        $this->expectException(MidtransException::class);

        $client->chargeTransaction(['transaction_details' => ['order_id' => '1', 'gross_amount' => 10000]]);
    }

    public function test_refund_requires_refund_key_when_retries_are_enabled(): void
    {
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 2),
            transport: new FakeTransport,
        );

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('refund_key is required');

        $client->refundTransaction('ORDER-1', ['amount' => 10000, 'reason' => 'out of stock']);
    }

    public function test_refund_is_allowed_with_a_refund_key(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 2),
            transport: $transport,
        );

        $client->refundTransaction('ORDER-1', ['refund_key' => 'refund-001', 'amount' => 10000]);

        self::assertStringContainsString('/v2/ORDER-1/refund', $transport->requests[0]['url']);
    }

    public function test_refund_without_refund_key_is_allowed_when_retries_are_off(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        $client->refundTransaction('ORDER-1', ['amount' => 10000]);

        self::assertCount(1, $transport->requests);
    }

    public function test_http_202_is_not_treated_as_a_final_result(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(202, '{"status_message":"still processing"}'));

        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        $this->expectException(MidtransPendingException::class);

        $client->chargeTransaction(['transaction_details' => ['order_id' => '1', 'gross_amount' => 10000]]);
    }

    public function test_error_status_code_in_a_200_body_is_surfaced(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"status_code":"402","status_message":"Merchant has no access for this payment type"}'));

        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        try {
            $client->chargeTransaction(['transaction_details' => ['order_id' => '1', 'gross_amount' => 10000]]);
            self::fail('Expected MidtransApiException was not thrown');
        } catch (MidtransApiException $exception) {
            self::assertSame(402, $exception->statusCode);
            self::assertStringContainsString('no access', $exception->getMessage());
        }
    }

    public function test_expired_transaction_status_code_407_stays_a_success(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"status_code":"407","transaction_status":"expire"}'));

        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        self::assertSame('expire', $client->getTransactionStatus('ORDER-1')['transaction_status']);
    }

    public function test_requests_carry_sdk_user_agent_and_configured_notification_headers(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(
                serverKey: 'sb-key',
                maxRetries: 0,
                appendNotificationUrl: 'https://shop.example/hooks/a',
                overrideNotificationUrl: 'https://shop.example/hooks/b',
                paymentLocale: 'en-EN',
                popId: 'pop-1',
            ),
            transport: $transport,
        );

        $client->getTransactionStatus('ORDER-1');
        $headers = $transport->requests[0]['headers'];

        self::assertStringStartsWith('aliziodev-midtrans-php/', $headers['User-Agent']);
        self::assertSame('https://shop.example/hooks/a', $headers['X-Append-Notification']);
        self::assertSame('https://shop.example/hooks/b', $headers['X-Override-Notification']);
        self::assertSame('en-EN', $headers['X-Payment-Locale']);
        self::assertSame('pop-1', $headers['X-POP-ID']);
    }

    public function test_transaction_status_b2b_uses_correct_endpoint(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        $client->getTransactionStatusB2b('ORDER-1');

        self::assertSame('GET', $transport->requests[0]['method']);
        self::assertStringContainsString('/v2/ORDER-1/status/b2b', $transport->requests[0]['url']);
    }

    public function test_refund_direct_uses_correct_endpoint(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        $client->refundTransactionDirect('ORDER-1', ['refund_key' => 'r1']);

        self::assertStringContainsString('/v2/ORDER-1/refund/online/direct', $transport->requests[0]['url']);
    }

    public function test_api_error_is_mapped_to_midtrans_api_exception(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(422, '{"status_message":"invalid request"}'));

        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        try {
            $client->chargeTransaction(['transaction_details' => ['order_id' => '1', 'gross_amount' => 10000]]);
            self::fail('Expected MidtransApiException was not thrown');
        } catch (MidtransApiException $exception) {
            self::assertSame(422, $exception->statusCode);
            self::assertSame('invalid request', $exception->getMessage());
            self::assertSame('invalid request', $exception->payload['status_message']);
        }
    }

    public function test_snap_helpers_return_token_and_url(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"token":"snap-token","redirect_url":"https://pay.example/snap"}'));
        $transport->pushResponse(new HttpResponse(200, '{"token":"snap-token-2","redirect_url":"https://pay.example/snap-2"}'));

        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        self::assertSame('snap-token', $client->getSnapToken(['transaction_details' => ['order_id' => '1', 'gross_amount' => 10000]]));
        self::assertSame('https://pay.example/snap-2', $client->getSnapUrl(['transaction_details' => ['order_id' => '2', 'gross_amount' => 10000]]));
    }

    public function test_card_endpoints_require_client_key(): void
    {
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', clientKey: null, maxRetries: 0),
            transport: new FakeTransport,
        );

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('Client key is required');

        $client->registerCard('4811111111111114', '12', '2029');
    }

    public function test_payment_link_endpoints_are_mapped_correctly(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        $client->createPaymentLink(['transaction_details' => ['order_id' => 'ORDER-PL-1', 'gross_amount' => 10000]]);
        $client->getPaymentLinkDetails('ORDER-PL-1');
        $client->deletePaymentLink('ORDER-PL-1');

        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame('https://api.sandbox.midtrans.com/v1/payment-links', $transport->requests[0]['url']);
        self::assertSame('GET', $transport->requests[1]['method']);
        self::assertSame('https://api.sandbox.midtrans.com/v1/payment-links/ORDER-PL-1', $transport->requests[1]['url']);
        self::assertSame('DELETE', $transport->requests[2]['method']);
        self::assertSame('https://api.sandbox.midtrans.com/v1/payment-links/ORDER-PL-1', $transport->requests[2]['url']);
    }

    public function test_balance_mutation_endpoint_is_mapped_correctly(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        $client->getBalanceMutation('IDR', '2026-03-02T00:00:00+07:00', '2026-03-16T23:59:59+07:00');

        self::assertSame('GET', $transport->requests[0]['method']);
        self::assertStringStartsWith('https://api.sandbox.midtrans.com/v1/balance/mutation?', $transport->requests[0]['url']);
        self::assertStringContainsString('currency=IDR', $transport->requests[0]['url']);
        self::assertStringContainsString('start_time=', $transport->requests[0]['url']);
        self::assertStringContainsString('end_time=', $transport->requests[0]['url']);
    }

    public function test_invoicing_endpoints_are_mapped_correctly(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        $client->createInvoice([
            'order_id' => 'INV-ORDER-1',
            'invoice_number' => 'INV-001',
            'due_date' => '2026-05-01 10:00:00 +0700',
            'invoice_date' => '2026-04-01 10:00:00 +0700',
            'payment_type' => 'payment_link',
            'item_details' => [['item_id' => 'SKU-1', 'description' => 'Item', 'quantity' => 1, 'price' => 10000]],
            'customer_details' => ['name' => 'John', 'email' => 'john@example.com', 'phone' => '08123456789'],
        ]);
        $client->getInvoice('invoice-id-1');
        $client->voidInvoice('invoice-id-1');

        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame('https://api.sandbox.midtrans.com/v1/invoices', $transport->requests[0]['url']);
        self::assertSame('GET', $transport->requests[1]['method']);
        self::assertSame('https://api.sandbox.midtrans.com/v1/invoices/invoice-id-1', $transport->requests[1]['url']);
        self::assertSame('PATCH', $transport->requests[2]['method']);
        self::assertSame('https://api.sandbox.midtrans.com/v1/invoices/invoice-id-1/void', $transport->requests[2]['url']);
    }

    public function test_register_card_builds_the_query_from_card_and_client_key(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', clientKey: 'client-key-1', maxRetries: 0),
            transport: $transport,
        );

        $client->registerCard('4811111111111114', '12', '2029');

        $url = $transport->requests[0]['url'];

        self::assertSame('GET', $transport->requests[0]['method']);
        self::assertStringStartsWith('https://api.sandbox.midtrans.com/v2/card/register?', $url);
        self::assertStringContainsString('card_number=4811111111111114', $url);
        self::assertStringContainsString('card_exp_month=12', $url);
        self::assertStringContainsString('card_exp_year=2029', $url);
        self::assertStringContainsString('client_key=client-key-1', $url);
    }

    public function test_state_setting_patch_and_delete_are_retried(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 2),
            transport: $transport,
        );

        $client->voidInvoice('inv-1');
        $client->deletePaymentLink('ORDER-1');

        self::assertSame('PATCH', $transport->requests[0]['method']);
        self::assertSame(2, $transport->requests[0]['maxRetries'], 'A void only drives terminal state');
        self::assertSame('DELETE', $transport->requests[1]['method']);
        self::assertSame(2, $transport->requests[1]['maxRetries'], 'DELETE is idempotent by definition');
    }
}
