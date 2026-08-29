<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\Exceptions\MidtransException;
use Aliziodev\MidtransPhp\Http\HttpResponse;
use Aliziodev\MidtransPhp\SnapBi\ExternalId;
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

        $client = $this->client($transport);

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
        self::assertStringStartsWith('aliziodev-midtrans-php/', $transport->requests[0]['headers']['User-Agent']);
    }

    public function test_empty_external_id_is_rejected(): void
    {
        $client = $this->client(new FakeTransport);

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('X-EXTERNAL-ID must not be empty');

        $client->refundDirectDebit(
            payload: ['originalPartnerReferenceNo' => 'REF-1'],
            externalId: '   ',
            accessToken: 'token-123',
        );
    }

    public function test_error_response_code_in_a_200_body_is_surfaced(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"responseCode":"4045401","responseMessage":"Transaction Not Found"}'));

        try {
            $this->client($transport)->getDirectDebitStatus(
                payload: ['originalPartnerReferenceNo' => 'REF-1'],
                externalId: 'EXT-2',
                accessToken: 'token-123',
            );
            self::fail('Expected MidtransApiException was not thrown');
        } catch (MidtransApiException $exception) {
            self::assertSame(404, $exception->statusCode);
            self::assertSame('Transaction Not Found', $exception->getMessage());
        }
    }

    public function test_successful_response_code_passes_through(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"responseCode":"2005400","responseMessage":"Successful"}'));

        $result = $this->client($transport)->createVa(
            payload: ['partnerServiceId' => '  12345'],
            externalId: 'EXT-3',
            accessToken: 'token-123',
        );

        self::assertSame('Successful', $result['responseMessage']);
    }

    public function test_external_id_generator_produces_distinct_uuids(): void
    {
        $one = ExternalId::generate();
        $two = ExternalId::generate();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $one,
        );
        self::assertNotSame($one, $two);
    }

    public function test_access_token_is_reused_until_it_nears_expiry(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"accessToken":"tok-1","expiresIn":"900"}'));
        $transport->pushResponse(new HttpResponse(200, '{"responseCode":"2005400"}'));
        $transport->pushResponse(new HttpResponse(200, '{"responseCode":"2005400"}'));

        $client = $this->client($transport);
        $client->createDirectDebit(['partnerReferenceNo' => 'R1'], ExternalId::generate());
        $client->createDirectDebit(['partnerReferenceNo' => 'R2'], ExternalId::generate());

        $tokenCalls = array_filter(
            $transport->requests,
            static fn (array $request): bool => str_ends_with((string) $request['url'], '/v1.0/access-token/b2b'),
        );

        self::assertCount(1, $tokenCalls, 'The token should be minted once and reused');
        self::assertCount(3, $transport->requests);
    }

    public function test_clearing_the_cache_forces_a_new_token(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"accessToken":"tok-1","expiresIn":"900"}'));
        $transport->pushResponse(new HttpResponse(200, '{"responseCode":"2005400"}'));
        $transport->pushResponse(new HttpResponse(200, '{"accessToken":"tok-2","expiresIn":"900"}'));
        $transport->pushResponse(new HttpResponse(200, '{"responseCode":"2005400"}'));

        $client = $this->client($transport);
        $client->createDirectDebit(['partnerReferenceNo' => 'R1'], ExternalId::generate());
        $client->clearAccessTokenCache();
        $client->createDirectDebit(['partnerReferenceNo' => 'R2'], ExternalId::generate());

        self::assertCount(4, $transport->requests);
        self::assertSame('Bearer tok-2', $transport->requests[3]['headers']['Authorization']);
    }

    public function test_access_token_response_without_a_token_is_rejected(): void
    {
        $transport = new FakeTransport;
        $transport->pushResponse(new HttpResponse(200, '{"responseCode":"2007300"}'));

        $this->expectException(MidtransException::class);
        $this->expectExceptionMessage('carried no accessToken');

        $this->client($transport)->createDirectDebit(['partnerReferenceNo' => 'R1'], ExternalId::generate());
    }

    private function client(FakeTransport $transport): SnapBiClient
    {
        return new SnapBiClient(
            config: new MidtransConfig(
                serverKey: 'sb-key',
                maxRetries: 0,
                snapBiClientId: 'client-id',
                snapBiPrivateKey: (string) file_get_contents(__DIR__.'/Fixtures/snapbi_test_private.pem'),
                snapBiClientSecret: 'secret',
                snapBiPartnerId: 'partner',
                snapBiChannelId: '95221',
                snapBiDeviceId: 'dev-1',
            ),
            transport: $transport,
        );
    }
}
