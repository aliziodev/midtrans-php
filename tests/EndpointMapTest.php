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

/**
 * Pins the HTTP method and URL of every endpoint the SDK exposes.
 *
 * A wrong path in a payment SDK fails in production against a live merchant
 * account, so each one is asserted rather than assumed.
 */
final class EndpointMapTest extends TestCase
{
    private const CORE = 'https://api.sandbox.midtrans.com';

    private const SNAP = 'https://app.sandbox.midtrans.com/snap/v1';

    /**
     * @return array<string, array{0: \Closure, 1: string, 2: string}>
     */
    public static function coreEndpointProvider(): array
    {
        return [
            'snap create' => [fn (MidtransClient $c) => $c->createSnapTransaction(['transaction_details' => []]), 'POST', self::SNAP.'/transactions'],
            'charge' => [fn (MidtransClient $c) => $c->chargeTransaction(['payment_type' => 'gopay']), 'POST', self::CORE.'/v2/charge'],
            'capture' => [fn (MidtransClient $c) => $c->captureTransaction(['transaction_id' => 'T-1']), 'POST', self::CORE.'/v2/capture'],
            'status' => [fn (MidtransClient $c) => $c->getTransactionStatus('ORDER-1'), 'GET', self::CORE.'/v2/ORDER-1/status'],
            'status b2b' => [fn (MidtransClient $c) => $c->getTransactionStatusB2b('ORDER-1'), 'GET', self::CORE.'/v2/ORDER-1/status/b2b'],
            'approve' => [fn (MidtransClient $c) => $c->approveTransaction('ORDER-1'), 'POST', self::CORE.'/v2/ORDER-1/approve'],
            'deny' => [fn (MidtransClient $c) => $c->denyTransaction('ORDER-1'), 'POST', self::CORE.'/v2/ORDER-1/deny'],
            'cancel' => [fn (MidtransClient $c) => $c->cancelTransaction('ORDER-1'), 'POST', self::CORE.'/v2/ORDER-1/cancel'],
            'expire' => [fn (MidtransClient $c) => $c->expireTransaction('ORDER-1'), 'POST', self::CORE.'/v2/ORDER-1/expire'],
            'refund' => [fn (MidtransClient $c) => $c->refundTransaction('ORDER-1', ['refund_key' => 'r1']), 'POST', self::CORE.'/v2/ORDER-1/refund'],
            'refund direct' => [fn (MidtransClient $c) => $c->refundTransactionDirect('ORDER-1', ['refund_key' => 'r1']), 'POST', self::CORE.'/v2/ORDER-1/refund/online/direct'],
            'link account' => [fn (MidtransClient $c) => $c->linkPaymentAccount(['payment_type' => 'gopay']), 'POST', self::CORE.'/v2/pay/account'],
            'get account' => [fn (MidtransClient $c) => $c->getPaymentAccount('acc-1'), 'GET', self::CORE.'/v2/pay/account/acc-1'],
            'unlink account' => [fn (MidtransClient $c) => $c->unlinkPaymentAccount('acc-1'), 'POST', self::CORE.'/v2/pay/account/acc-1/unbind'],
            'point inquiry' => [fn (MidtransClient $c) => $c->getCardPointInquiry('tok-1'), 'GET', self::CORE.'/v2/point_inquiry/tok-1'],
            'create subscription' => [fn (MidtransClient $c) => $c->createSubscription(['name' => 'Plan']), 'POST', self::CORE.'/v1/subscriptions'],
            'get subscription' => [fn (MidtransClient $c) => $c->getSubscription('SUB-1'), 'GET', self::CORE.'/v1/subscriptions/SUB-1'],
            'update subscription' => [fn (MidtransClient $c) => $c->updateSubscription('SUB-1', ['name' => 'Pro']), 'PATCH', self::CORE.'/v1/subscriptions/SUB-1'],
            'disable subscription' => [fn (MidtransClient $c) => $c->disableSubscription('SUB-1'), 'POST', self::CORE.'/v1/subscriptions/SUB-1/disable'],
            'enable subscription' => [fn (MidtransClient $c) => $c->enableSubscription('SUB-1'), 'POST', self::CORE.'/v1/subscriptions/SUB-1/enable'],
            'cancel subscription' => [fn (MidtransClient $c) => $c->cancelSubscription('SUB-1'), 'POST', self::CORE.'/v1/subscriptions/SUB-1/cancel'],
        ];
    }

    #[DataProvider('coreEndpointProvider')]
    public function test_core_endpoint_is_mapped(\Closure $call, string $method, string $url): void
    {
        $transport = new FakeTransport;

        $call(new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        ));

        self::assertSame($method, $transport->requests[0]['method']);
        self::assertSame($url, $transport->requests[0]['url']);
    }

    /**
     * Path parameters must be encoded, otherwise an order id containing a slash
     * or a space silently addresses a different resource.
     */
    public function test_path_parameters_are_url_encoded(): void
    {
        $transport = new FakeTransport;
        $client = new MidtransClient(
            config: new MidtransConfig(serverKey: 'sb-key', maxRetries: 0),
            transport: $transport,
        );

        $client->getTransactionStatus('ORDER/1 #2');

        self::assertSame(self::CORE.'/v2/ORDER%2F1%20%232/status', $transport->requests[0]['url']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function snapBiEndpointProvider(): array
    {
        return [
            'direct debit create' => ['createDirectDebit', '/v1.0/debit/payment-host-to-host'],
            'direct debit status' => ['directDebitStatus', '/v1.0/debit/status'],
            'direct debit cancel' => ['directDebitCancel', '/v1.0/debit/cancel'],
            'direct debit refund' => ['directDebitRefund', '/v1.0/debit/refund'],
            'va create' => ['createVa', '/v1.0/transfer-va/create-va'],
            'va status' => ['vaStatus', '/v1.0/transfer-va/status'],
            'va cancel' => ['vaCancel', '/v1.0/transfer-va/delete-va'],
            'qris create' => ['createQris', '/v1.0/qr/qr-mpm-generate'],
            'qris status' => ['qrisStatus', '/v1.0/qr/qr-mpm-query'],
            'qris cancel' => ['qrisCancel', '/v1.0/qr/qr-mpm-cancel'],
            'qris refund' => ['qrisRefund', '/v1.0/qr/qr-mpm-refund'],
        ];
    }

    #[DataProvider('snapBiEndpointProvider')]
    public function test_snap_bi_endpoint_is_mapped(string $method, string $expectedPath): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"responseCode":"2000000"}'));

        $client = new SnapBiClient(
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

        $client->{$method}(['partnerReferenceNo' => 'REF-1'], 'EXT-1', 'token-123');

        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame(self::CORE.$expectedPath, $transport->requests[0]['url']);
    }

    public function test_access_token_endpoint_is_mapped(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"accessToken":"tok"}'));

        $client = new SnapBiClient(
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

        $client->getAccessToken();

        self::assertSame('POST', $transport->requests[0]['method']);
        self::assertSame(self::CORE.'/v1.0/access-token/b2b', $transport->requests[0]['url']);
        self::assertSame('client-id', $transport->requests[0]['headers']['X-CLIENT-KEY']);
        self::assertArrayHasKey('X-SIGNATURE', $transport->requests[0]['headers']);
    }
}
