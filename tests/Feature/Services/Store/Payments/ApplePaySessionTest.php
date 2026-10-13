<?php

namespace Tests\Feature\Services\Store\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Store\Payments\GatewayConfig;
use App\Services\Store\Payments\MyFatoorahException;
use App\Services\Store\Payments\PaymentOutcome;
use App\Services\Store\Payments\PaymentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesMyFatoorah;
use Tests\TestCase;

/**
 * The Apple Pay button on the shop's own page: how its payment is opened,
 * reused, checked with MyFatoorah and caught up on.
 */
class ApplePaySessionTest extends TestCase
{
    use FakesMyFatoorah, LazilyRefreshDatabase;

    private const PAYMENT_ID = '07076389491322460173';

    private function payments(): PaymentService
    {
        return $this->app->make(PaymentService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureMyFatoorah();
        config(['myfatoorah.apple_pay_on_site' => true]);
    }

    private function order(): Order
    {
        return Order::factory()->pendingPayment()->create([
            'total_fils' => 40_000, 'customer_name' => 'Sara Al-Ahmad', 'customer_email' => 'sara@example.com',
            'customer_phone' => '+96551234567', 'locale' => 'ar',
        ]);
    }

    /**
     * An Apple Pay attempt for the order, as applePaySession() leaves it.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function attempt(Order $order, string $sessionId = 'KWT-SESSION-1', array $overrides = []): Payment
    {
        return Payment::factory()->create($overrides + [
            'order_id' => $order->id, 'reference' => 'REF-'.$order->id, 'amount_fils' => 40_000, 'method' => 'APPLE_PAY',
            'mf_session_id' => $sessionId, 'mf_invoice_id' => null, 'mf_payment_url' => null,
        ]);
    }

    // ------------------------------------------------------------ whether

    public function test_apple_pay_is_taken_on_the_site_only_when_it_is_switched_on_and_the_environment_is_known(): void
    {
        $this->assertTrue($this->payments()->takesOnSite('APPLE_PAY'));
        $this->assertTrue($this->payments()->takesOnSite('apple_pay'));
        $this->assertFalse($this->payments()->takesOnSite('KNET'));
        $this->assertFalse($this->payments()->takesOnSite(null));

        config(['myfatoorah.apple_pay_on_site' => false]);
        $this->assertFalse($this->payments()->takesOnSite('APPLE_PAY'));

        config(['myfatoorah.apple_pay_on_site' => true, 'myfatoorah.api_url' => 'https://somewhere-else.example']);
        $this->assertFalse($this->payments()->takesOnSite('APPLE_PAY'));
    }

    public function test_the_script_for_the_button_is_the_one_that_belongs_to_the_endpoint_in_use(): void
    {
        $gateway = $this->app->make(GatewayConfig::class);

        $this->assertSame('https://demo.myfatoorah.com/sessions/v1/session.js', $gateway->sdkUrl());

        config(['myfatoorah.api_url' => 'https://api.myfatoorah.com/']);
        $this->assertSame('https://portal.myfatoorah.com/sessions/v1/session.js', $gateway->sdkUrl());

        config(['myfatoorah.api_url' => 'https://api-sa.myfatoorah.com']);
        $this->assertSame('https://sa.myfatoorah.com/sessions/v1/session.js', $gateway->sdkUrl());
    }

    public function test_what_an_owner_saved_in_the_panel_wins_over_the_servers_setting(): void
    {
        $gateway = $this->app->make(GatewayConfig::class);
        GatewayCredential::factory()->create(['apple_pay_on_site' => false]);

        $this->assertFalse($gateway->applePayOnSite());
        $this->assertSame(GatewayConfig::SOURCE_PANEL, $gateway->applePayOnSiteSource());
    }

    // ------------------------------------------------------------ opening

    public function test_opening_the_button_makes_an_attempt_and_a_session_for_exactly_the_orders_amount(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/sessions' => Http::response($this->createdSession('KWT-SESSION-1'), 201)]);
        $order = $this->order();

        $payment = $this->payments()->applePaySession($order);

        $this->assertSame([PaymentState::Pending, 40_000, 'KWD', 'APPLE_PAY', 'KWT-SESSION-1'], [
            $payment->state, $payment->amount_fils, $payment->currency, $payment->method, $payment->mf_session_id,
        ]);
        $this->assertNull($payment->mf_payment_url);
        $this->assertNull($payment->mf_invoice_id);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://apitest.myfatoorah.com/v3/sessions' && $request->data() === [
            'PaymentMode' => 'COMPLETE_PAYMENT',
            'Order' => ['Amount' => 40, 'Currency' => 'KWD', 'ExternalIdentifier' => $payment->reference],
            'SupportedPaymentMethods' => ['applepay'],
            'Customer' => [
                'Name' => 'Sara Al-Ahmad',
                'Mobile' => ['CountryCode' => '+965', 'Number' => '51234567'],
                'Email' => 'sara@example.com',
                'Reference' => $order->number,
            ],
            'IntegrationUrls' => [
                'Redirection' => url('/ar-KW/payment/return'),
                'Webhook' => url('/api/webhooks/myfatoorah'),
            ],
            'Language' => 'AR',
            'MetaData' => ['UDF1' => $order->number],
        ]);
    }

    public function test_a_session_nobody_has_paid_on_is_kept_when_the_page_is_reloaded(): void
    {
        $order = $this->order();
        $kept = $this->attempt($order);
        $this->fakeMyFatoorah([self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails())]);

        $payment = $this->payments()->applePaySession($order);

        $this->assertTrue($payment->is($kept));
        $this->assertSame(1, Payment::query()->count());
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    #[DataProvider('spentSessions')]
    public function test_a_session_that_is_spent_or_has_run_out_is_never_shown_again(string $name, array $changes): void
    {
        $order = $this->order();
        $old = $this->attempt($order);
        $this->fakeMyFatoorah([
            self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails($name, $changes)),
            self::MF.'/v3/sessions' => Http::response($this->createdSession('KWT-SESSION-2'), 201),
        ]);

        $payment = $this->payments()->applePaySession($order);

        $this->assertFalse($payment->is($old));
        $this->assertSame('KWT-SESSION-2', $payment->mf_session_id);
    }

    /**
     * @return array<string,array{0:string,1:array<string,mixed>}>
     */
    public static function spentSessions(): array
    {
        return [
            'already paid on' => ['paid', []],
            'used, without a payment' => ['unused', ['IsUsed' => true]],
            'expired' => ['unused', ['SessionExpiry' => '2020-01-01T00:00:00Z']],
        ];
    }

    public function test_a_session_my_fatoorah_no_longer_knows_is_replaced(): void
    {
        $order = $this->order();
        $this->attempt($order);
        $this->fakeMyFatoorah([
            self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response(['IsSuccess' => false, 'Message' => 'Invalid data'], 400),
            self::MF.'/v3/sessions' => Http::response($this->createdSession('KWT-SESSION-2'), 201),
        ]);

        $this->assertSame('KWT-SESSION-2', $this->payments()->applePaySession($order)->mf_session_id);
    }

    public function test_an_attempt_whose_hour_is_up_is_not_reused_even_if_its_session_is_still_open(): void
    {
        $order = $this->order();
        $this->attempt($order, overrides: ['expires_at' => now()->subMinute()]);
        $this->fakeMyFatoorah([self::MF.'/v3/sessions' => Http::response($this->createdSession('KWT-SESSION-2'), 201)]);

        $this->assertSame('KWT-SESSION-2', $this->payments()->applePaySession($order)->mf_session_id);
    }

    public function test_when_the_session_cannot_be_opened_the_attempt_is_marked_failed_and_the_error_surfaces(): void
    {
        $order = $this->order();
        $this->fakeMyFatoorah([self::MF.'/v3/sessions' => Http::response(['IsSuccess' => false, 'Message' => 'Not allowed'], 400)]);

        try {
            $this->payments()->applePaySession($order);
            $this->fail('The failure should have surfaced.');
        } catch (MyFatoorahException) {
            $payment = Payment::query()->firstOrFail();
            $this->assertSame(PaymentState::Failed, $payment->state);
            $this->assertNull($payment->mf_session_id);
            $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
        }
    }

    // ----------------------------------------------------------- checking

    public function test_a_session_nobody_has_paid_on_has_nothing_to_settle(): void
    {
        $order = $this->order();
        $payment = $this->attempt($order);
        $this->fakeMyFatoorah([self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails())]);

        $this->assertNull($this->payments()->checkSession($payment));
        $this->assertSame(PaymentStatus::Unpaid, $order->refresh()->payment_status);
    }

    public function test_a_session_that_was_paid_on_settles_the_order_from_the_payment_itself_and_remembers_the_invoice(): void
    {
        $order = $this->order();
        $payment = $this->attempt($order);
        $this->fakeMyFatoorah([
            self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails('paid')),
            self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference, ['Transaction.PaymentMethod' => 'Apple Pay'])),
        ]);

        $outcome = $this->payments()->checkSession($payment);

        $this->assertSame(PaymentOutcome::PAID, $outcome->result);
        $payment->refresh();
        $this->assertSame([PaymentState::Paid, self::PAYMENT_ID, '6148108', 'Apple Pay'], [$payment->state, $payment->mf_payment_id, $payment->mf_invoice_id, $payment->mf_method]);
        $order->refresh();
        $this->assertSame([PaymentStatus::Paid, OrderStatus::New], [$order->payment_status, $order->status]);
    }

    public function test_the_payment_is_found_by_its_invoice_when_my_fatoorah_did_not_echo_our_reference(): void
    {
        $order = $this->order();
        $payment = $this->attempt($order);
        $this->fakeMyFatoorah([
            self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails('paid')),
            self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails('', ['Invoice.Id' => '6148108'])),
        ]);

        $this->assertTrue($this->payments()->checkSession($payment)->isPaid());
    }

    public function test_an_amount_that_is_not_the_orders_is_flagged_for_a_person_and_nothing_is_fulfilled(): void
    {
        $order = $this->order();
        $payment = $this->attempt($order);
        $this->fakeMyFatoorah([
            self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails('paid')),
            self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference, [
                'Amount.ValueInDisplayCurrency' => '39', 'Amount.ValueInBaseCurrency' => '39',
            ])),
        ]);

        $outcome = $this->payments()->checkSession($payment);

        $this->assertSame(PaymentOutcome::PENDING, $outcome->result);
        $this->assertSame(Payment::ANOMALY_AMOUNT_MISMATCH, $payment->refresh()->anomaly);
        $this->assertSame(PaymentStatus::Unpaid, $order->refresh()->payment_status);
    }

    public function test_a_declined_apple_pay_is_recorded_and_the_order_keeps_waiting(): void
    {
        $order = $this->order();
        $payment = $this->attempt($order);
        $this->fakeMyFatoorah([
            self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails('paid', ['TransactionResult.Invoice.Status' => 'PENDING', 'TransactionResult.Transaction.Status' => 'FAILED'])),
            self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference, [
                'Invoice.Status' => 'PENDING', 'Transaction.Status' => 'FAILED', 'Transaction.Error.Message' => 'DECLINED : Do not honour',
            ])),
        ]);

        $outcome = $this->payments()->checkSession($payment);

        $this->assertSame(PaymentOutcome::FAILED, $outcome->result);
        $this->assertSame([PaymentState::Failed, 'DECLINED : Do not honour'], [$payment->refresh()->state, $payment->failure_reason]);
        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
    }

    // ------------------------------------------------------- catching up

    public function test_reconcile_finds_an_apple_pay_payment_through_its_session_when_nobody_came_back(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $order = $this->order();
        $payment = $this->attempt($order, overrides: ['created_at' => now()->subMinutes(10)]);
        $this->fakeMyFatoorah([
            self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails('paid')),
            self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference)),
        ]);

        $counts = $this->payments()->reconcile();

        $this->assertSame(['checked' => 1, 'paid' => 1, 'expired' => 0, 'unreachable' => 0], $counts);
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
    }

    public function test_reconcile_lets_an_unused_session_run_out_and_gives_the_order_back(): void
    {
        $this->travelTo('2026-10-13 12:00:00');
        $order = $this->order();
        $payment = $this->attempt($order, overrides: ['created_at' => now()->subHours(2), 'expires_at' => now()->subMinutes(30)]);
        $this->fakeMyFatoorah([self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails())]);

        $counts = $this->payments()->reconcile();

        $this->assertSame(['checked' => 1, 'paid' => 0, 'expired' => 1, 'unreachable' => 0], $counts);
        $this->assertSame(PaymentState::Expired, $payment->refresh()->state);
        $this->assertSame(OrderStatus::Cancelled, $order->refresh()->status);
    }
}
