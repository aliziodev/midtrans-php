<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests\Integration;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\MidtransClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SandboxSmokeTest extends TestCase
{
    #[Group('integration')]
    public function test_sandbox_connectivity_with_real_credentials(): void
    {
        if (getenv('MIDTRANS_SMOKE_TEST') !== '1') {
            self::markTestSkipped('Set MIDTRANS_SMOKE_TEST=1 to run sandbox smoke test.');
        }

        $serverKey = (string) getenv('MIDTRANS_SERVER_KEY');
        if ($serverKey === '') {
            self::markTestSkipped('Set MIDTRANS_SERVER_KEY to run sandbox smoke test.');
        }

        $isProduction = strtolower((string) getenv('MIDTRANS_IS_PRODUCTION')) === 'true';

        $client = new MidtransClient(new MidtransConfig(
            serverKey: $serverKey,
            isProduction: $isProduction,
            maxRetries: 1,
            retryDelayMs: 200,
        ));

        try {
            $response = $client->transactionStatus('SMOKE-'.bin2hex(random_bytes(6)));
            self::assertIsArray($response);
        } catch (MidtransApiException $exception) {
            self::assertIsArray($exception->payload);
            self::assertGreaterThanOrEqual(400, $exception->statusCode);
            self::assertLessThan(600, $exception->statusCode);
        }
    }
}
