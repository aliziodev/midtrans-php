<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Http\HttpResponse;
use Aliziodev\MidtransPhp\MidtransClient;
use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;
use Aliziodev\MidtransPhp\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NewEndpointsTest extends TestCase
{
    public function test_bin_api_endpoint(): void
    {
        $transport = new FakeTransport;

        $this->coreClient($transport)->getBin('48111111');

        self::assertSame('GET', $transport->requests[0]['method']);
        self::assertSame('https://api.sandbox.midtrans.com/v1/bins/48111111', $transport->requests[0]['url']);
    }

    public function test_convert_invoice_endpoint(): void
    {
        $transport = new FakeTransport;
        $client = $this->coreClient($transport);

        $client->convertInvoice('inv-1');
        $client->convertInvoice('inv-2', ['client' => ['email' => 'buyer@example.com']]);

        // POST despite the documentation saying PATCH: the API answers 405 to
        // PATCH and PUT, and 200 to POST.
        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame('https://api.sandbox.midtrans.com/v1/invoices/inv-1/convert', $transport->requests[0]['url']);
        self::assertNull($transport->requests[0]['jsonBody'], 'An empty override set must not send a body');
        self::assertSame('{"client":{"email":"buyer@example.com"}}', $transport->requests[1]['jsonBody']);
    }

    public function test_cancel_snap_session_endpoint(): void
    {
        $transport = new FakeTransport;

        $this->coreClient($transport)->cancelSnapSession('snap-token-1');

        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame(
            'https://app.sandbox.midtrans.com/snap/v1/transactions/snap-token-1/cancel',
            $transport->requests[0]['url'],
        );
    }

    public function test_snap_preference_endpoints_use_the_v3_host(): void
    {
        $transport = new FakeTransport;
        $client = $this->coreClient($transport);

        $client->getSnapPreferences();
        $client->updateSnapPreferences(['display_name' => 'My Shop']);

        self::assertSame('GET', $transport->requests[0]['method']);
        self::assertSame(
            'https://app.sandbox.midtrans.com/snap/v3/merchant-preferences',
            $transport->requests[0]['url'],
        );
        self::assertSame('PATCH', $transport->requests[1]['method']);
        self::assertSame('{"display_name":"My Shop"}', $transport->requests[1]['jsonBody']);
    }

    public function test_gopay_promotion_endpoint_with_and_without_account_id(): void
    {
        $transport = new FakeTransport;
        $client = $this->coreClient($transport);

        $client->getGopayPromotions('acc-1', 25000);
        $client->getGopayPromotions(null, '25000', 'IDR');

        self::assertStringStartsWith(
            'https://api.sandbox.midtrans.com/v2/gopay/promo/acc-1?',
            $transport->requests[0]['url'],
        );
        self::assertStringContainsString('gross_amount=25000', $transport->requests[0]['url']);
        self::assertStringContainsString('currency=IDR', $transport->requests[0]['url']);
        self::assertStringStartsWith(
            'https://api.sandbox.midtrans.com/v2/gopay/promo/?',
            $transport->requests[1]['url'],
        );
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function snapBiEndpointProvider(): array
    {
        return [
            ['bindAccount', '/v1.0/registration-account-binding'],
            ['unbindAccount', '/v1.0/registration-account-unbinding'],
            ['getAccountBindingStatus', '/v1.0/registration-account-inquiry'],
            ['captureAuthorization', '/v1.0/auth/capture'],
            ['voidAuthorization', '/v1.0/auth/void'],
            ['getTransactionHistoryList', '/v1.0/transaction-history-list'],
            ['getTransactionHistoryDetail', '/v1.0/transaction-history-detail'],
        ];
    }

    #[DataProvider('snapBiEndpointProvider')]
    public function test_snap_bi_endpoint_paths(string $method, string $expectedPath): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"responseCode":"2000000"}'));

        $this->snapBiClient($transport)->{$method}(['partnerReferenceNo' => 'REF-1'], 'EXT-1', 'token-123');

        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame('https://merchants.sbx.midtrans.com'.$expectedPath, $transport->requests[0]['url']);
        self::assertSame('EXT-1', $transport->requests[0]['headers']['X-EXTERNAL-ID']);
    }

    private function coreClient(FakeTransport $transport): MidtransClient
    {
        return new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );
    }

    private function snapBiClient(FakeTransport $transport): SnapBiClient
    {
        return new SnapBiClient(
            config: new MidtransConfig(
                serverKey: 'sb-key',
                maxRetries: 0,
                snapBiClientId: 'client-id',
                snapBiPrivateKey: (string) file_get_contents(__DIR__.'/Fixtures/snapbi_test_private.pem'),
                snapBiClientSecret: 'secret',
                snapBiPartnerId: 'partner',
            ),
            transport: $transport,
        );
    }
}
