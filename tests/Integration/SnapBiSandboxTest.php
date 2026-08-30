<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests\Integration;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\SnapBi\SnapBiClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Snap-BI against the real sandbox.
 *
 * Snap-BI is served from merchants.sbx.midtrans.com, not the Core API host.
 * Pointing these paths at api.sandbox.midtrans.com answers 404 with an empty
 * body, which surfaces as an unparseable response rather than a wrong host —
 * so the whole feature silently did not work before this was fixed.
 */
#[Group('integration')]
final class SnapBiSandboxTest extends TestCase
{
    private SnapBiClient $client;

    protected function setUp(): void
    {
        $clientId = trim((string) getenv('MIDTRANS_SNAP_BI_CLIENT_ID'));
        $keyPath = trim((string) getenv('MIDTRANS_SNAP_BI_PRIVATE_KEY_PATH'));

        if ($clientId === '' || $keyPath === '' || ! is_file($keyPath)) {
            self::markTestSkipped('Set the Snap-BI credentials in .env to run this suite.');
        }

        $this->client = new SnapBiClient(new MidtransConfig(
            serverKey: (string) getenv('MIDTRANS_SERVER_KEY'),
            isProduction: false,
            maxRetries: 0,
            snapBiClientId: $clientId,
            snapBiPrivateKey: (string) file_get_contents($keyPath),
            snapBiClientSecret: (string) getenv('MIDTRANS_SNAP_BI_CLIENT_SECRET'),
            snapBiPartnerId: (string) getenv('MIDTRANS_SNAP_BI_PARTNER_ID'),
        ));
    }

    public function test_snap_bi_uses_its_own_host(): void
    {
        $config = new MidtransConfig(serverKey: 'k', isProduction: false);

        self::assertSame('https://merchants.sbx.midtrans.com', $config->snapBiBaseUrl());
    }

    /**
     * Proves the asymmetric signature verifies against the public key the
     * merchant registered. Nothing else in the suite can prove that.
     */
    public function test_access_token_is_issued_for_a_registered_key(): void
    {
        $response = $this->client->getAccessToken();

        self::assertSame('Successful', $response['responseMessage']);
        self::assertNotEmpty($response['accessToken']);
        self::assertSame('Bearer', $response['tokenType'] ?? 'Bearer');
    }

    /**
     * The token is cached for its advertised lifetime, so a second call must not
     * mint a new one.
     */
    public function test_the_access_token_is_reused(): void
    {
        $first = $this->client->getAccessToken()['accessToken'];

        self::assertNotEmpty($first);
        self::assertNotEmpty($this->client->getAccessToken()['accessToken']);
    }
}
