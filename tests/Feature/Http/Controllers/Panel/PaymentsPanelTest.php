<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Store\Pricing\CommerceSettings;
use App\Services\Store\Pricing\PricingContext;
use App\Services\Store\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMyFatoorah;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class PaymentsPanelTest extends TestCase
{
    use FakesMyFatoorah, LazilyRefreshDatabase, SignsInStaff;

    private const PAYMENT_ID = '07076389491322460173';

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs('manager');
    }

    /**
     * @return array{0:Order,1:Payment}
     */
    private function waitingPayment(): array
    {
        $order = Order::factory()->pendingPayment()->create(['total_fils' => 40_000]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'reference' => 'REF-'.$order->id, 'amount_fils' => 40_000, 'mf_payment_id' => self::PAYMENT_ID,
        ]);

        return [$order, $payment];
    }

    // ---------------------------------------------------------------- gateway

    public function test_the_page_says_so_when_online_payment_is_not_set_up(): void
    {
        config(['myfatoorah.api_key' => '', 'myfatoorah.webhook_secret' => '']);

        $this->get(route('panel.payments.index'))
            ->assertOk()
            ->assertSee('Not active')
            ->assertSee('Online payment is not active')
            ->assertDontSee('Test the MyFatoorah connection');
    }

    public function test_a_sandbox_key_is_shown_as_sandbox_and_a_live_endpoint_as_live_without_ever_printing_the_key(): void
    {
        $this->configureMyFatoorah('whsec-never-shown-either');

        $sandbox = $this->get(route('panel.payments.index'))->assertOk()->assertSee('Sandbox')->assertSee('apitest.myfatoorah.com');
        $sandbox->assertDontSee(self::MF_KEY)->assertDontSee('whsec-never-shown-either');

        config(['myfatoorah.api_url' => 'https://api.myfatoorah.com']);
        $this->startNewRequest();

        $this->get(route('panel.payments.index'))
            ->assertSee('Live')->assertSee('api.myfatoorah.com')->assertDontSee(self::MF_KEY);
    }

    public function test_the_page_shows_whether_the_webhook_secret_is_set(): void
    {
        $this->configureMyFatoorah(null);
        $this->get(route('panel.payments.index'))->assertSee('Not set');

        $this->configureMyFatoorah('a-secret');
        $this->startNewRequest();
        $this->get(route('panel.payments.index'))->assertSee('Set');
    }

    public function test_the_webhook_address_to_put_in_the_my_fatoorah_dashboard_is_shown_and_follows_the_host_override(): void
    {
        $this->configureMyFatoorah();

        $this->get(route('panel.payments.index'))->assertSee(url('/api/webhooks/myfatoorah'), false);

        config(['myfatoorah.callback_base_url' => 'https://shop.example.com']);
        $this->startNewRequest();

        $this->get(route('panel.payments.index'))->assertSee('https://shop.example.com/api/webhooks/myfatoorah', false);
    }

    public function test_the_connection_test_reports_the_methods_the_account_has_enabled_and_refreshes_checkouts_list(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah();
        Cache::put('myfatoorah:methods:en', [['id' => 'stale', 'type' => 'x', 'label' => 'Stale']], 600);

        $this->post(route('panel.payments.test'))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'Connected to MyFatoorah') && str_contains($message, 'KNET'));

        $this->assertNull(Cache::get('myfatoorah:methods:en'), 'checkout reads the fresh list next time');
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.connection_tested']);
    }

    public function test_a_key_that_may_not_list_methods_is_explained_rather_than_called_broken(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah([self::MF.'/v3/payment-methods' => Http::response(['IsSuccess' => false, 'Message' => 'Forbidden'], 403)]);

        $this->post(route('panel.payments.test'))
            ->assertSessionHas('warning', fn (string $message) => str_contains($message, 'single “Pay online” choice'));
    }

    public function test_an_unreachable_gateway_is_reported_as_such(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah([self::MF.'/v3/payment-methods' => Http::failedConnection()]);

        $this->post(route('panel.payments.test'))->assertSessionHas('warning', __('panel.payments.unreachable', [], 'en'));
    }

    public function test_the_connection_cannot_be_tested_when_there_is_nothing_to_test(): void
    {
        config(['myfatoorah.api_key' => '']);
        Http::preventStrayRequests();

        $this->post(route('panel.payments.test'))->assertSessionHas('warning', __('panel.payments.notConfigured', [], 'en'));
    }

    // ---------------------------------------------------------------- history

    public function test_the_attempts_are_listed_newest_first_and_can_be_filtered_by_state_and_searched(): void
    {
        $paid = Payment::factory()->paid('pay-id-1')->create(['mf_invoice_id' => '6111111']);
        $failed = Payment::factory()->withState(PaymentState::Failed)->create(['mf_invoice_id' => '6222222', 'failure_reason' => 'Card declined']);
        $names = fn (array $query) => $this->get(route('panel.payments.index', $query))->viewData('payments')->pluck('id')->all();

        $this->assertSame([$failed->id, $paid->id], $names([]));
        $this->assertSame([$failed->id], $names(['state' => 'failed']));
        $this->assertSame([$paid->id], $names(['q' => '6111111']));
        $this->assertSame([$failed->id], $names(['q' => $failed->order->number]));
        $this->assertSame([$failed->id, $paid->id], $names(['state' => 'bogus']));

        $this->get(route('panel.payments.index'))->assertSee('Card declined');
    }

    public function test_payments_that_need_a_look_are_flagged_and_can_be_listed_on_their_own(): void
    {
        $odd = Payment::factory()->paid('pay-id-1')->create(['anomaly' => Payment::ANOMALY_AMOUNT_MISMATCH]);
        $looked = Payment::factory()->paid('pay-id-2')->create(['anomaly' => Payment::ANOMALY_DUPLICATE, 'anomaly_reviewed_at' => now()]);
        Payment::factory()->paid('pay-id-3')->create();

        $response = $this->get(route('panel.payments.index', ['review' => 1]));

        $this->assertSame([$odd->id], $response->viewData('payments')->pluck('id')->all());
        $this->get(route('panel.payments.index'))
            ->assertSee('1 payment needs review.')
            ->assertSee('The amount paid does not match the order')
            ->assertSee('A second payment for the same order');
        $this->assertNotNull($looked->fresh()->anomaly);
    }

    public function test_marking_a_flagged_payment_reviewed_records_who_and_when_and_keeps_the_flag(): void
    {
        $manager = $this->signInAs('manager');
        $payment = Payment::factory()->paid('pay-id-1')->create(['anomaly' => Payment::ANOMALY_ORDER_CANCELLED]);

        $this->post(route('panel.payments.review', $payment))->assertSessionHas('status');

        $payment->refresh();
        $this->assertSame(Payment::ANOMALY_ORDER_CANCELLED, $payment->anomaly);
        $this->assertNotNull($payment->anomaly_reviewed_at);
        $this->assertSame($manager->id, $payment->anomaly_reviewed_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.reviewed', 'user_id' => $manager->id]);
        $this->assertSame(0, Payment::query()->needsReview()->count());
    }

    public function test_reviewing_a_payment_with_nothing_wrong_does_nothing(): void
    {
        $payment = Payment::factory()->paid()->create();

        $this->post(route('panel.payments.review', $payment));

        $this->assertNull($payment->fresh()->anomaly_reviewed_at);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'payment.reviewed']);
    }

    // ---------------------------------------------------------------- re-check

    public function test_checking_a_payment_again_settles_the_order_when_my_fatoorah_says_it_was_paid(): void
    {
        $this->configureMyFatoorah();
        [$order, $payment] = $this->waitingPayment();
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference))]);

        $this->post(route('panel.payments.verify', $payment))
            ->assertSessionHas('status', __('panel.payments.recheck.paid', [], 'en'));

        $this->assertSame(PaymentState::Paid, $payment->fresh()->state);
        $order->refresh();
        $this->assertSame(OrderStatus::New, $order->status);
        $this->assertSame(PaymentStatus::Paid, $order->payment_status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.rechecked', 'subject_label' => $order->number]);
    }

    public function test_a_payment_still_pending_at_my_fatoorah_stays_pending_and_says_so(): void
    {
        $this->configureMyFatoorah();
        [$order, $payment] = $this->waitingPayment();
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference, [
            'Invoice.Status' => 'PENDING', 'Transaction.Status' => 'INPROGRESS',
        ]))]);

        $this->post(route('panel.payments.verify', $payment))
            ->assertSessionHas('warning', __('panel.payments.recheck.pending', [], 'en'));

        $this->assertSame(PaymentState::Pending, $payment->fresh()->state);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_an_amount_that_does_not_match_the_order_is_not_accepted_just_because_staff_asked_again(): void
    {
        $this->configureMyFatoorah();
        [$order, $payment] = $this->waitingPayment();
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference, ['Amount.ValueInDisplayCurrency' => '1', 'Amount.ValueInBaseCurrency' => '1', 'Amount.ValueInPayCurrency' => '1']))]);

        $this->post(route('panel.payments.verify', $payment))->assertSessionHas('warning');

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Unpaid, $order->fresh()->payment_status);
        $this->assertSame(Payment::ANOMALY_AMOUNT_MISMATCH, $payment->fresh()->anomaly);
    }

    public function test_an_unreachable_gateway_changes_nothing_and_tells_staff_to_try_again(): void
    {
        $this->configureMyFatoorah();
        [$order, $payment] = $this->waitingPayment();
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::failedConnection()]);

        $this->post(route('panel.payments.verify', $payment))->assertSessionHas('warning', __('panel.payments.unreachable', [], 'en'));

        $this->assertSame(PaymentState::Pending, $payment->fresh()->state);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->status);
    }

    public function test_a_gateway_that_refuses_the_question_is_reported(): void
    {
        $this->configureMyFatoorah();
        [, $payment] = $this->waitingPayment();
        $this->fakeMyFatoorah([self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response(['IsSuccess' => false, 'Message' => 'Forbidden'], 403)]);

        $this->post(route('panel.payments.verify', $payment))->assertSessionHas('warning', __('panel.payments.refused', [], 'en'));
    }

    public function test_nothing_is_asked_of_the_gateway_when_it_is_not_configured(): void
    {
        config(['myfatoorah.api_key' => '']);
        Http::preventStrayRequests();
        [, $payment] = $this->waitingPayment();

        $this->post(route('panel.payments.verify', $payment))->assertSessionHas('warning', __('panel.payments.notConfigured', [], 'en'));
    }

    public function test_the_check_again_button_is_offered_only_for_a_pending_payment_and_only_when_there_is_a_gateway(): void
    {
        [, $pending] = $this->waitingPayment();
        $paid = Payment::factory()->paid('pay-id-9')->create();

        config(['myfatoorah.api_key' => '']);
        $this->get(route('panel.payments.index'))->assertDontSee(route('panel.payments.verify', $pending), false);

        $this->configureMyFatoorah();
        $this->startNewRequest();
        $this->get(route('panel.payments.index'))
            ->assertSee(route('panel.payments.verify', $pending), false)
            ->assertDontSee(route('panel.payments.verify', $paid), false);
    }

    // ------------------------------------------------------------ cash

    public function test_cash_on_delivery_and_its_fee_are_saved_and_read_back_by_checkout(): void
    {
        $this->put(route('panel.payments.settings'), ['cod_enabled' => '1', 'cod_fee' => '٠٫٥٠٠'])->assertSessionHasNoErrors();

        $commerce = app(CommerceSettings::class);
        $this->assertTrue($commerce->cashOnDeliveryEnabled());
        $this->assertSame(500, $commerce->codFeeFils());

        $this->put(route('panel.payments.settings'), ['cod_fee' => '']);
        $this->assertFalse($commerce->cashOnDeliveryEnabled(), 'an unticked switch is off');
        $this->assertSame(0, $commerce->codFeeFils());
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.settings_updated']);
    }

    public function test_the_pricing_engine_adds_the_fee_only_for_cash_and_withholds_cash_when_it_is_switched_off(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        $basket = ['line' => ['productId' => (string) $product->id, 'quantity' => 1, 'options' => []]];
        $engine = app(PricingEngine::class);

        $this->put(route('panel.payments.settings'), ['cod_enabled' => '1', 'cod_fee' => '0.750']);

        $this->assertSame(750, $engine->price($basket, new PricingContext(cashOnDelivery: true))->codFeeFils);
        $this->assertSame(0, $engine->price($basket, new PricingContext(cashOnDelivery: false))->codFeeFils);
        $this->assertTrue($engine->price($basket, new PricingContext)->cashOnDeliveryAvailable);

        $this->put(route('panel.payments.settings'), ['cod_fee' => '0.750']);
        $this->assertFalse($engine->price($basket, new PricingContext)->cashOnDeliveryAvailable);
    }

    public function test_a_fee_that_is_not_money_is_refused(): void
    {
        $this->put(route('panel.payments.settings'), ['cod_enabled' => '1', 'cod_fee' => 'a bit'])->assertSessionHasErrors('cod_fee');
    }
}
