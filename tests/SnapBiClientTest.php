<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Http\HttpResponse;
use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;
use Aliziodev\MidtransPhp\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class SnapBiClientTest extends TestCase
{
    public function test_missing_snap_bi_credentials_throws(): void
    {
        $client = new SnapBiClient(
            config: new MidtransConfig(serverKey: 'sb-key'),
            transport: new FakeTransport,
        );

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('Missing Snap-BI client ID');

        $client->getAccessToken();
    }

    public function test_create_direct_debit_uses_expected_path_and_headers(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"responseCode":"2005400","responseMessage":"Success"}'));

        $client = new SnapBiClient(
            config: new MidtransConfig(
                serverKey: 'sb-key',
                snapBiClientId: 'client-id',
                snapBiPrivateKey: 'dummy-private-key-for-test',
                snapBiClientSecret: 'secret',
                snapBiPartnerId: 'partner',
                snapBiChannelId: '95221',
                snapBiDeviceId: 'dev-1',
                maxRetries: 0,
            ),
            transport: $transport,
        );

        $client->createDirectDebit(
            payload: [
                'partnerReferenceNo' => 'REF-1',
                'amount' => ['value' => '10000.00', 'currency' => 'IDR'],
            ],
            externalId: 'EXT-1',
            accessToken: 'token-123',
        );

        self::assertCount(1, $transport->requests);
        self::assertStringEndsWith('/v1.0/debit/payment-host-to-host', $transport->requests[0]['url']);
        self::assertSame('Bearer token-123', $transport->requests[0]['headers']['Authorization']);
        self::assertSame('EXT-1', $transport->requests[0]['headers']['X-EXTERNAL-ID']);
    }
}
