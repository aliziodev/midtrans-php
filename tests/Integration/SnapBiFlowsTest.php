<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests\Integration;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\SnapBi\ExternalId;
use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Snap-BI transactional flows against the real sandbox.
 *
 * These need the symmetric HMAC signature and X-PARTNER-ID on top of the access
 * token, so a passing run proves considerably more than the token test does: it
 * proves the HMAC string is assembled exactly as Midtrans assembles it,
 * including the minified body hash.
 */
#[Group('integration')]
final class SnapBiFlowsTest extends TestCase
{
    private SnapBiClient $client;

    private string $partnerId;

    protected function setUp(): void
    {
        $clientId = trim((string) getenv('MIDTRANS_SNAP_BI_CLIENT_ID'));
        $keyPath = trim((string) getenv('MIDTRANS_SNAP_BI_PRIVATE_KEY_PATH'));
        $this->partnerId = trim((string) getenv('MIDTRANS_SNAP_BI_PARTNER_ID'));

        if ($clientId === '' || $this->partnerId === '' || $keyPath === '' || ! is_file($keyPath)) {
            self::markTestSkipped('Set the Snap-BI credentials in .env to run this suite.');
        }

        $config = new MidtransConfig(
            serverKey: (string) getenv('MIDTRANS_SERVER_KEY'),
            isProduction: false,
            maxRetries: 0,
            snapBiClientId: $clientId,
            snapBiPrivateKey: (string) file_get_contents($keyPath),
            snapBiClientSecret: (string) getenv('MIDTRANS_SNAP_BI_CLIENT_SECRET'),
            snapBiPartnerId: $this->partnerId,
        );

        if (! str_contains($config->snapBiBaseUrl(), 'sbx')) {
            self::fail('Refusing to run: the client is not pointed at the Snap-BI sandbox.');
        }

        $this->client = new SnapBiClient($config);
    }

    public function test_a_qris_code_can_be_generated_queried_and_cancelled(): void
    {
        $reference = $this->reference('QR');

        $generated = $this->client->createQris([
            'partnerReferenceNo' => $reference,
            'amount' => ['value' => '15000.00', 'currency' => 'IDR'],
            'merchantId' => $this->partnerId,
            'validityPeriod' => date('c', strtotime('+1 day')),
            'additionalInfo' => ['acquirer' => 'gopay'],
        ], ExternalId::generate());

        self::assertSame('2004700', $generated['responseCode']);
        // A real QRIS payload, not a placeholder.
        self::assertStringStartsWith('00020101', $generated['qrContent']);

        $midtransReference = $generated['referenceNo'];

        $status = $this->client->getQrisStatus([
            'originalPartnerReferenceNo' => $reference,
            'originalReferenceNo' => $midtransReference,
            'serviceCode' => '47',
            'merchantId' => $this->partnerId,
        ], ExternalId::generate());

        self::assertSame('2005100', $status['responseCode']);

        $cancelled = $this->client->cancelQris([
            'originalPartnerReferenceNo' => $reference,
            'originalReferenceNo' => $midtransReference,
            'merchantId' => $this->partnerId,
            'reason' => 'sandbox flow test',
        ], ExternalId::generate());

        self::assertSame('2007700', $cancelled['responseCode']);
    }

    public function test_a_direct_debit_can_be_created_queried_and_cancelled(): void
    {
        $reference = $this->reference('DD');

        $created = $this->client->createDirectDebit([
            'partnerReferenceNo' => $reference,
            'amount' => ['value' => '15000.00', 'currency' => 'IDR'],
            'urlParam' => [[
                'url' => 'https://example.com/return',
                'type' => 'PAY_RETURN',
                'isDeeplink' => 'Y',
            ]],
            // Mandatory, and easy to miss: without it Midtrans answers
            // "Invalid Mandatory Field payOptionDetails".
            'payOptionDetails' => [[
                'payMethod' => 'GOPAY',
                'payOption' => 'GOPAY',
                'transAmount' => ['value' => '15000.00', 'currency' => 'IDR'],
            ]],
            'additionalInfo' => [
                'customerDetails' => ['firstName' => 'Sandbox', 'email' => 'sandbox@example.com'],
            ],
        ], ExternalId::generate());

        self::assertSame('2005400', $created['responseCode']);

        $midtransReference = $created['referenceNo'];

        $status = $this->client->getDirectDebitStatus([
            'originalPartnerReferenceNo' => $reference,
            'originalReferenceNo' => $midtransReference,
            'serviceCode' => '54',
        ], ExternalId::generate());

        self::assertSame('2005500', $status['responseCode']);

        $cancelled = $this->client->cancelDirectDebit([
            'originalPartnerReferenceNo' => $reference,
            'originalReferenceNo' => $midtransReference,
            'reason' => 'sandbox flow test',
        ], ExternalId::generate());

        self::assertSame('2005700', $cancelled['responseCode']);
    }

    public function test_transaction_history_can_be_listed(): void
    {
        $history = $this->client->getTransactionHistoryList([
            'fromDateTime' => date('c', strtotime('-1 day')),
            'toDateTime' => date('c'),
        ], ExternalId::generate());

        self::assertSame('2001200', $history['responseCode']);
    }

    /**
     * partnerServiceId is assigned by Midtrans per merchant and padded to a
     * fixed width, so a made-up value is rejected on format. Recorded rather
     * than skipped: the path, the access token and the HMAC signature are all
     * exercised, and only the merchant-specific value is missing.
     */
    public function test_creating_a_virtual_account_needs_the_assigned_partner_service_id(): void
    {
        try {
            $this->client->createVa([
                'partnerServiceId' => '  12345',
                'customerNo' => '00000000001',
                'virtualAccountNo' => '  1234500000000001',
                'virtualAccountName' => 'Sandbox Tester',
                'trxId' => $this->reference('VA'),
                'totalAmount' => ['value' => '15000.00', 'currency' => 'IDR'],
                'virtualAccountTrxType' => '1',
                'expiredDate' => date('c', strtotime('+1 day')),
            ], ExternalId::generate());

            self::markTestIncomplete('createVa now succeeds: a real partnerServiceId is configured, so assert the VA instead.');
        } catch (\Aliziodev\MidtransPhp\Exceptions\MidtransApiException $exception) {
            self::assertSame(400, $exception->statusCode);
            self::assertStringContainsString('partnerServiceId', (string) $exception->payload['responseMessage']);
        }
    }

    private function reference(string $prefix): string
    {
        return $prefix.date('His').bin2hex(random_bytes(2));
    }
}
