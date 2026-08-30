<?php

declare(strict_types=1);

namespace Aliziodev\MidtransPhp\Tests\Integration;

use Aliziodev\MidtransPhp\Config\MidtransConfig;
use Aliziodev\MidtransPhp\Exceptions\MidtransApiException;
use Aliziodev\MidtransPhp\MidtransClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Card payments, pre-authorisation and the subscription lifecycle, against the
 * real sandbox.
 *
 * These need a saved_token_id, which needs a card token, which is why this file
 * calls the deprecated server-side tokenization endpoint. The card is Midtrans's
 * published sandbox number, so the PCI reasoning behind that deprecation does
 * not apply here. Application code must still tokenize in the browser.
 */
#[Group('integration')]
final class CardAndSubscriptionTest extends TestCase
{
    private const SANDBOX_PREFIX = 'SB-Mid-server-';

    /** Midtrans's published sandbox test card. Never a real PAN. */
    private const TEST_CARD = '4811111111111114';

    private MidtransClient $client;

    protected function setUp(): void
    {
        $serverKey = trim((string) getenv('MIDTRANS_SERVER_KEY'));
        $clientKey = trim((string) getenv('MIDTRANS_CLIENT_KEY'));

        if ($serverKey === '' || $serverKey === self::SANDBOX_PREFIX || $clientKey === '') {
            self::markTestSkipped('Set MIDTRANS_SERVER_KEY and MIDTRANS_CLIENT_KEY in .env to run this suite.');
        }

        if (! str_starts_with($serverKey, self::SANDBOX_PREFIX)) {
            self::fail('Refusing to run: MIDTRANS_SERVER_KEY is not a sandbox key.');
        }

        $this->client = new MidtransClient(new MidtransConfig(
            serverKey: $serverKey,
            clientKey: $clientKey,
            isProduction: false,
            maxRetries: 0,
        ));
    }

    public function test_a_card_charge_can_save_a_token_for_later_use(): void
    {
        $charge = $this->client->chargeTransaction([
            'payment_type' => 'credit_card',
            'transaction_details' => ['order_id' => $this->orderId('CARD'), 'gross_amount' => 20000],
            'credit_card' => [
                'token_id' => $this->cardToken(),
                'save_token_id' => true,
                'authentication' => false,
            ],
        ]);

        self::assertSame('capture', $charge['transaction_status']);
        self::assertSame('accept', $charge['fraud_status']);
        self::assertNotEmpty($charge['saved_token_id']);
    }

    /**
     * The whole lifecycle in one test on purpose: each step needs the
     * subscription the previous one produced, and asserting a state change by
     * reading it back is the only way to know the call did anything.
     */
    public function test_the_subscription_lifecycle(): void
    {
        $subscription = $this->client->createSubscription([
            'name' => 'Sub'.date('His'),
            'amount' => '20000',
            'currency' => 'IDR',
            'payment_type' => 'credit_card',
            'token' => $this->savedToken(),
            'schedule' => [
                'interval' => 1,
                'interval_unit' => 'month',
                'max_interval' => 3,
                'start_time' => date('Y-m-d H:i:s O', strtotime('+2 days')),
            ],
            'customer_details' => [
                'first_name' => 'Sandbox',
                'email' => 'sandbox@example.com',
                'phone' => '628123456789',
            ],
        ]);

        $id = $subscription['id'];

        self::assertSame('active', $subscription['status']);
        self::assertSame('20000', $this->client->getSubscription($id)['amount']);

        $this->client->updateSubscription($id, ['amount' => '25000']);
        self::assertSame('25000', $this->client->getSubscription($id)['amount']);

        $this->client->disableSubscription($id);
        self::assertSame('inactive', $this->client->getSubscription($id)['status']);

        $this->client->enableSubscription($id);
        self::assertSame('active', $this->client->getSubscription($id)['status']);

        $this->client->cancelSubscription($id);
        self::assertSame('inactive', $this->client->getSubscription($id)['status']);
    }

    public function test_a_pre_authorisation_can_be_captured(): void
    {
        $orderId = $this->orderId('CAU');

        $authorised = $this->client->chargeTransaction([
            'payment_type' => 'credit_card',
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 40000],
            'credit_card' => [
                'token_id' => $this->cardToken(),
                'authentication' => false,
                'type' => 'authorize',
            ],
        ]);

        // authorize holds the funds; nothing has been taken yet.
        self::assertSame('authorize', $authorised['transaction_status']);

        $captured = $this->client->captureTransaction([
            'transaction_id' => $authorised['transaction_id'],
            'gross_amount' => 40000,
        ]);

        self::assertSame('capture', $captured['transaction_status']);
    }

    public function test_a_pre_authorisation_can_be_released_instead(): void
    {
        $orderId = $this->orderId('CVD');

        $this->client->chargeTransaction([
            'payment_type' => 'credit_card',
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 35000],
            'credit_card' => [
                'token_id' => $this->cardToken(),
                'authentication' => false,
                'type' => 'authorize',
            ],
        ]);

        $this->client->cancelTransaction($orderId);

        self::assertSame('cancel', $this->client->getTransactionStatus($orderId)['transaction_status']);
    }

    /**
     * A card refund needs the transaction to have reached settlement. Attempted
     * while it is still in capture — which is where a fresh charge sits until
     * the settlement batch runs — Midtrans refuses it with 412.
     *
     * 412 covers three separate causes, all confirmed against this sandbox:
     * an unrefundable method (bank transfer), an unsettled transaction (this
     * test), and an account without refund permission — a settled GoPay
     * transaction well inside its 45-day window is refused too.
     *
     * @see https://docs.midtrans.com/docs/what-payment-method-that-have-refund-feature
     */
    public function test_a_card_refund_before_settlement_is_refused(): void
    {
        $orderId = $this->orderId('CRF');

        $this->client->chargeTransaction([
            'payment_type' => 'credit_card',
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => 50000],
            'credit_card' => ['token_id' => $this->cardToken(), 'authentication' => false],
        ]);

        try {
            $this->client->refundTransaction($orderId, [
                'refund_key' => $orderId.'-r1',
                'amount' => 20000,
                'reason' => 'sandbox refund test',
            ]);
            self::markTestIncomplete('Refund now succeeds: this account has gained the permission, so assert the refund instead.');
        } catch (MidtransApiException $exception) {
            self::assertSame(412, $exception->statusCode);
        }
    }

    private function cardToken(): string
    {
        // Single use: every charge needs a token of its own.
        return $this->client->getCardToken(self::TEST_CARD, '12', '2030', '123')['token_id'];
    }

    private function savedToken(): string
    {
        return $this->client->chargeTransaction([
            'payment_type' => 'credit_card',
            'transaction_details' => ['order_id' => $this->orderId('SUBCARD'), 'gross_amount' => 20000],
            'credit_card' => [
                'token_id' => $this->cardToken(),
                'save_token_id' => true,
                'authentication' => false,
            ],
        ])['saved_token_id'];
    }

    private function orderId(string $prefix): string
    {
        return $prefix.'-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
    }
}
