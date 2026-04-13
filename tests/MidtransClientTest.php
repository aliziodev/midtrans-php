<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Http\HttpResponse;
use Aliziodev\MidtransPhp\MidtransClient;
use Aliziodev\MidtransPhp\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class MidtransClientTest extends TestCase
{
    public function test_retry_without_idempotency_throws_for_mutating_request(): void
    {
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 1),
            transport: new FakeTransport,
        );

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('Idempotency-Key is required');

        $client->coreCharge(['transaction_details' => ['order_id' => '1', 'gross_amount' => 10000]]);
    }

    public function test_mutating_request_includes_idempotency_header(): void
    {
        $transport = new FakeTransport;
        $client = (new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 1),
            transport: $transport,
        ))->withIdempotencyKey('idem-123');

        $client->coreCharge(['transaction_details' => ['order_id' => '1', 'gross_amount' => 10000]]);

        self::assertSame('idem-123', $transport->requests[0]['headers']['Idempotency-Key']);
        self::assertSame('https://api.sandbox.midtrans.com/v2/charge', $transport->requests[0]['url']);
    }

    public function test_transaction_status_b2b_uses_correct_endpoint(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        $client->transactionStatusB2b('ORDER-1');

        self::assertSame('GET', $transport->requests[0]['method']);
        self::assertStringContainsString('/v2/ORDER-1/status/b2b', $transport->requests[0]['url']);
    }

    public function test_refund_direct_uses_correct_endpoint(): void
    {
        $transport = new FakeTransport;
        $client = (new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        ))->withIdempotencyKey('idem-refund');

        $client->refundTransactionDirect('ORDER-1', ['refund_key' => 'r1']);

        self::assertStringContainsString('/v2/ORDER-1/refund/online/direct', $transport->requests[0]['url']);
    }

    public function test_api_error_is_mapped_to_midtrans_api_exception(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(422, '{"status_message":"invalid request"}'));

        $client = (new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        ))->withIdempotencyKey('idem-err');

        try {
            $client->coreCharge(['transaction_details' => ['order_id' => '1', 'gross_amount' => 10000]]);
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

        $client = (new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        ))->withIdempotencyKey('idem-snap');

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

        $client->cardRegister('4811111111111114', '12', '2029');
    }

    public function test_payment_link_endpoints_are_mapped_correctly(): void
    {
        $transport = new FakeTransport;
        $client = (new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        ))->withIdempotencyKey('idem-plink');

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
        $client = (new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        ))->withIdempotencyKey('idem-invoice');

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
}
