<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests\Integration;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\MidtransClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Whole business flows against the real sandbox, rather than one endpoint at a
 * time.
 *
 * Creating something and then reading, voiding or converting it is what proves
 * the SDK models the feature. Probing endpoints individually only proves they
 * answer — which is how convertInvoice shipped using the PATCH the docs
 * specify, while the API accepts only POST.
 */
#[Group('integration')]
final class CoreApiFlowsTest extends TestCase
{

    private MidtransClient $client;

    /** @var array<string, string> */
    private array $customer = [
        'id' => 'cust-1',
        'name' => 'Sandbox Tester',
        'email' => 'sandbox@example.com',
        // Must be in 62 form: Midtrans rejects a leading zero on invoices.
        'phone' => '628123456789',
    ];

    protected function setUp(): void
    {
        $serverKey = trim((string) getenv('MIDTRANS_SERVER_KEY'));

        if ($serverKey === '') {
            self::markTestSkipped('Set MIDTRANS_SERVER_KEY in .env to run the sandbox suite.');
        }


        $config = new MidtransConfig(
            serverKey: $serverKey,
            isProduction: false,
            maxRetries: 0,
        );

        $this->assertSandbox($config);

        $this->client = new MidtransClient($config);
    }

    public function test_payment_link_can_be_created_read_and_deleted(): void
    {
        $orderId = $this->orderId('PL');

        $created = $this->client->createPaymentLink([
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 25000],
            'usage_limit' => 1,
            'customer_details' => $this->customer,
        ]);

        self::assertSame($orderId, $created['order_id']);
        self::assertStringContainsString('payment-links', $created['payment_url']);

        $details = $this->client->getPaymentLinkDetails($orderId);
        self::assertNotEmpty($details['payment_link_url']);
        self::assertNotEmpty($details['enabled_payments']);

        $this->client->deletePaymentLink($orderId);

        // Deleting really removes it, rather than only disabling payment.
        $this->expectException(MidtransApiException::class);
        $this->client->getPaymentLinkDetails($orderId);
    }

    public function test_invoice_can_be_created_read_and_voided(): void
    {
        $invoice = $this->client->createInvoice($this->invoicePayload($this->orderId('INV')));

        self::assertSame('pending', $invoice['status']);
        self::assertNotEmpty($invoice['pdf_url']);

        self::assertSame($invoice['id'], $this->client->getInvoice($invoice['id'])['id']);

        $this->client->voidInvoice($invoice['id']);

        // A voided invoice stays readable; only its status changes.
        self::assertSame('voided', $this->client->getInvoice($invoice['id'])['status']);
    }

    /**
     * Regression: this used PATCH, which the documentation specifies and the
     * API answers 405 to. Only POST is accepted.
     */
    public function test_a_quotation_converts_into_an_invoice(): void
    {
        $orderId = $this->orderId('QUO');

        $quotation = $this->client->createInvoice(
            $this->invoicePayload($orderId) + [
                'document_type' => 'quotation',
                'quotation_date' => date('Y-m-d H:i:s O'),
                'quotation_validity_date' => date('Y-m-d H:i:s O', strtotime('+7 days')),
            ]
        );

        self::assertSame('quotation', $quotation['document_type']);

        $converted = $this->client->convertInvoice($quotation['id']);

        self::assertSame($orderId, $converted['order_id']);
    }

    public function test_a_virtual_account_can_be_expired_before_payment(): void
    {
        $orderId = $this->orderId('VAX');

        $this->client->chargeTransaction([
            'payment_type' => 'bank_transfer',
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 18000],
            'bank_transfer' => ['bank' => 'permata'],
        ]);

        $this->client->expireTransaction($orderId);

        self::assertSame('expire', $this->client->getTransactionStatus($orderId)['transaction_status']);
    }

    /**
     * Refunding money that never arrived has to be refused by Midtrans, not by
     * an optimistic client.
     *
     * GoPay is refundable once settled, so the refusal here is about the
     * transaction being unpaid rather than about the method.
     */
    public function test_an_unsettled_transaction_cannot_be_refunded(): void
    {
        $orderId = $this->orderId('REF');

        $this->client->chargeTransaction([
            'payment_type' => 'gopay',
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 15000],
        ]);

        try {
            $this->client->refundTransaction($orderId, [
                'refund_key' => $orderId.'-r1',
                'amount' => 15000,
                'reason' => 'sandbox flow test',
            ]);
            self::fail('Expected MidtransApiException was not thrown');
        } catch (MidtransApiException $exception) {
            self::assertSame(412, $exception->statusCode);
        }
    }

    public function test_gopay_account_linking_reaches_the_channel(): void
    {
        $result = $this->client->linkPaymentAccount([
            'payment_type' => 'gopay',
            'gopay_partner' => [
                'phone_number' => '628123456789',
                'country_code' => '62',
                'redirect_url' => 'https://example.com/return',
            ],
        ]);

        // 202 with a channel response means the request reached GoPay. Whether
        // that number is a registered sandbox account is not the SDK's concern.
        self::assertSame('202', $result['status_code']);
        self::assertArrayHasKey('channel_response_code', $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(string $orderId): array
    {
        return [
            'order_id' => $orderId,
            'invoice_number' => $orderId,
            'due_date' => date('Y-m-d H:i:s O', strtotime('+14 days')),
            'invoice_date' => date('Y-m-d H:i:s O'),
            'customer_details' => $this->customer,
            'item_details' => [
                ['item_id' => 'SKU-1', 'description' => 'Sandbox item', 'quantity' => 1, 'price' => 25000],
            ],
            'payment_type' => 'payment_link',
            'amount' => ['vat' => 0, 'discount' => 0, 'shipping' => 0],
        ];
    }

    /**
     * getTransactionStatusB2b reads the corporate-billing view of a
     * transaction, so it needs a payment type that produces a bill: echannel
     * (Mandiri Bill) does, and a card charge does not. It answers a list of
     * B2B payment attempts rather than the single object /v2/{id}/status
     * returns, which is the whole reason it is a separate method.
     */
    public function test_an_echannel_bill_can_be_read_back_as_a_b2b_status(): void
    {
        $orderId = $this->orderId('B2B');

        $charge = $this->client->chargeTransaction([
            'payment_type' => 'echannel',
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 15000],
            'echannel' => ['bill_info1' => 'Payment For:', 'bill_info2' => 'Sandbox test'],
        ]);

        self::assertSame('201', $charge['status_code']);
        self::assertNotEmpty($charge['bill_key']);
        self::assertNotEmpty($charge['biller_code']);

        $status = $this->client->getTransactionStatusB2b($orderId);

        self::assertIsArray($status);
    }

    private function orderId(string $prefix): string
    {
        return $prefix.'-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
    }

    /**
     * Refuses to run unless the client is pointed at the sandbox host.
     *
     * Asserted from the resolved base URL, not from the key's prefix. Sandbox
     * keys used to start with SB-Mid-server- and newer ones start with
     * Mid-server-, which is what production keys have always looked like — so
     * the prefix no longer tells the two apart. The host does, and the host is
     * what decides whether these tests can move real money.
     */
    private function assertSandbox(MidtransConfig $config): void
    {
        if (! str_contains($config->coreBaseUrl(), 'sandbox')) {
            self::fail('Refusing to run: the client is not pointed at the Midtrans sandbox.');
        }
    }
}
