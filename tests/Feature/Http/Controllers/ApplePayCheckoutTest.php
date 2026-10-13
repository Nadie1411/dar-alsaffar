<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryCity;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Services\Store\Payments\PaymentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMyFatoorah;
use Tests\Concerns\IsolatesSettings;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

/**
 * Paying with the Apple Pay button on the shop's own page: from choosing it at
 * checkout, through the page that shows the button, to what happens once the
 * button says it is done.
 */
class ApplePayCheckoutTest extends TestCase
{
    use FakesMyFatoorah, IsolatesSettings, LazilyRefreshDatabase, UsesLocalStore;

    private const PAYMENT_ID = '07076389491322460173';

    private DeliveryArea $area;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = DeliveryArea::factory()->for(DeliveryCity::factory(), 'city')->create(['fee_fils' => 2_000]);
        $this->product = Product::factory()->priced(35_000)->fixedDiscount(16_000)->create();
        $this->configureMyFatoorah();
        config(['myfatoorah.apple_pay_on_site' => true]);
    }

    /**
     * @return array<string,mixed>
     */
    private function basket(): array
    {
        return ['cart.items' => ['line' => [
            'productId' => (string) $this->product->id, 'quantity' => 2, 'varientId' => null, 'options' => [],
        ]]];
    }

    /**
     * @return array<string,mixed>
     */
    private function form(string $method = 'APPLE_PAY'): array
    {
        return [
            'fullName' => 'Sara Al-Ahmad', 'email' => 'sara@example.com', 'phone' => '51234567',
            'city' => (string) $this->area->delivery_city_id, 'area' => (string) $this->area->id,
            'block' => '4', 'street' => 'Street 12', 'building' => '7',
            'payment' => 'online', 'paymentMethod' => $method,
        ];
    }

    /**
     * An order waiting for its KWD 40 payment, with the Apple Pay attempt made for it.
     *
     * @return array{0:Order,1:Payment}
     */
    private function waitingOrder(): array
    {
        $order = Order::factory()->pendingPayment()->create(['total_fils' => 40_000]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'reference' => 'REF-'.$order->id, 'amount_fils' => 40_000, 'method' => 'APPLE_PAY',
            'mf_session_id' => 'KWT-SESSION-1', 'mf_invoice_id' => null, 'mf_payment_url' => null,
        ]);

        return [$order, $payment];
    }

    /**
     * Have MyFatoorah answer for the session the shopper just used.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function sessionPaid(Payment $payment, array $overrides = []): void
    {
        $this->fakeMyFatoorah($overrides + [
            self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails('paid')),
            self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference)),
        ]);
    }

    // -------------------------------------------------------------- checkout

    public function test_the_apple_pay_tile_is_marked_so_devices_without_apple_pay_can_leave_it_out(): void
    {
        $this->fakeMyFatoorah();
        $marked = '/<label class="pay-method"[^>]*data-apple-pay/';

        $on = $this->withSession($this->basket())->get('/en-KW/checkout');
        $on->assertOk();
        $this->assertMatchesRegularExpression($marked, $on->getContent());

        config(['myfatoorah.apple_pay_on_site' => false]);
        $this->app->forgetScopedInstances();

        $off = $this->withSession($this->basket())->get('/en-KW/checkout');
        $off->assertOk()->assertSee('Apple Pay');
        $this->assertDoesNotMatchRegularExpression($marked, $off->getContent());
    }

    public function test_choosing_apple_pay_places_the_order_and_goes_to_the_shops_own_page_without_opening_an_invoice(): void
    {
        $this->fakeMyFatoorah();

        $response = $this->withSession($this->basket())->post('/en-KW/checkout', $this->form());

        $response->assertRedirect(url('/en-KW/checkout/apple-pay/DS-100001'))
            ->assertSessionHas('cart.items')
            ->assertSessionHas('payment.order', 'DS-100001');
        $order = Order::query()->firstOrFail();
        $this->assertSame([OrderStatus::PendingPayment, 40_000], [$order->status, $order->total_fils]);
        $this->assertSame(0, Payment::query()->count());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v3/payments'));
    }

    public function test_with_the_button_switched_off_apple_pay_is_paid_on_my_fatoorahs_page_as_before(): void
    {
        config(['myfatoorah.apple_pay_on_site' => false]);
        $this->fakeMyFatoorah();

        $response = $this->withSession($this->basket())->post('/en-KW/checkout', $this->form());

        $response->assertRedirect('https://demo.MyFatoorah.com/KWT/ie/050754719614810863-ce9138bf');
        $this->assertSame('APPLE_PAY', Payment::query()->firstOrFail()->method);
    }

    public function test_the_other_methods_still_go_to_my_fatoorahs_page_while_the_button_is_on(): void
    {
        $this->fakeMyFatoorah();

        $this->withSession($this->basket())->post('/en-KW/checkout', $this->form('KNET'))
            ->assertRedirect('https://demo.MyFatoorah.com/KWT/ie/050754719614810863-ce9138bf');
    }

    // -------------------------------------------------------------- the page

    public function test_the_page_shows_the_order_the_amount_and_the_button_on_a_session_opened_for_it(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/sessions' => Http::response($this->createdSession('KWT-SESSION-1'), 201)]);
        $order = Order::factory()->pendingPayment()->create(['total_fils' => 40_000]);

        $response = $this->withSession(['payment.order' => $order->number])->get('/en-KW/checkout/apple-pay/'.$order->number);

        $response->assertOk()
            ->assertSee('Pay with Apple Pay')
            ->assertSee($order->number)
            ->assertSee('40 KWD')
            ->assertSee('data-session="KWT-SESSION-1"', false)
            ->assertSee('data-sdk="https://demo.myfatoorah.com/sessions/v1/session.js"', false)
            ->assertSee('data-confirm="'.url('/en-KW/checkout/apple-pay/'.$order->number.'/confirm').'"', false)
            ->assertSee(url('/en-KW/checkout/pay/'.$order->number), false)
            ->assertSee('Pay another way');
        $this->assertSame(['KWT-SESSION-1', 40_000], [Payment::query()->firstOrFail()->mf_session_id, Payment::query()->firstOrFail()->amount_fils]);
    }

    public function test_reloading_the_page_keeps_the_same_unused_session(): void
    {
        [$order] = $this->waitingOrder();
        $this->fakeMyFatoorah([self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails())]);

        $this->withSession(['payment.order' => $order->number])->get('/en-KW/checkout/apple-pay/'.$order->number)
            ->assertOk()->assertSee('data-session="KWT-SESSION-1"', false);

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_the_page_speaks_arabic_on_the_arabic_site(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/sessions' => Http::response($this->createdSession('KWT-SESSION-1'), 201)]);
        $order = Order::factory()->pendingPayment()->create(['total_fils' => 40_000]);

        $this->withSession(['payment.order' => $order->number])->get('/ar-KW/checkout/apple-pay/'.$order->number)
            ->assertOk()->assertSee('الدفع عبر أبل باي')->assertSee('data-language="ar"', false);
    }

    public function test_someone_who_paid_and_reloads_is_taken_on_not_offered_a_second_payment(): void
    {
        [$order, $payment] = $this->waitingOrder();
        $this->sessionPaid($payment);

        $response = $this->withSession(['payment.order' => $order->number, 'cart.items' => $this->basket()['cart.items']])
            ->get('/en-KW/checkout/apple-pay/'.$order->number);

        $response->assertRedirect('/en-KW/checkout/thanks/'.$order->number)->assertSessionMissing('cart.items');
        $this->assertSame(PaymentStatus::Paid, $order->refresh()->payment_status);
        $this->assertSame(1, Payment::query()->count());
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
    }

    public function test_an_order_that_is_already_paid_goes_straight_to_the_thank_you_page(): void
    {
        $order = Order::factory()->paid()->create();

        $this->withSession(['payment.order' => $order->number])->get('/en-KW/checkout/apple-pay/'.$order->number)
            ->assertRedirect('/en-KW/checkout/thanks/'.$order->number);
    }

    public function test_when_the_session_cannot_be_opened_the_page_says_so_and_offers_another_way_to_pay(): void
    {
        $this->fakeMyFatoorah([self::MF.'/v3/sessions' => Http::response(['IsSuccess' => false, 'Message' => 'down'], 503)]);
        $order = Order::factory()->pendingPayment()->create();

        $response = $this->withSession(['payment.order' => $order->number])->get('/en-KW/checkout/apple-pay/'.$order->number);

        $response->assertOk()
            ->assertSee('We could not get Apple Pay ready right now.')
            ->assertSee('Pay another way')
            ->assertDontSee('data-session', false);
        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
    }

    public function test_the_page_belongs_to_the_shopper_who_placed_the_order_and_to_nobody_else(): void
    {
        $this->fakeMyFatoorah();
        $order = Order::factory()->forCustomer(Customer::factory()->create())->pendingPayment()->create();

        $this->get('/en-KW/checkout/apple-pay/'.$order->number)->assertNotFound();
        $this->withSession(['payment.order' => 'DS-999999'])->get('/en-KW/checkout/apple-pay/'.$order->number)->assertNotFound();
        $this->actingAs(Customer::factory()->create(), 'customer')->get('/en-KW/checkout/apple-pay/'.$order->number)->assertNotFound();
        $this->withSession(['payment.order' => $order->number])->get('/en-KW/checkout/apple-pay/DS-000000')->assertNotFound();
    }

    public function test_there_is_no_page_for_a_cancelled_order_or_while_the_button_is_off(): void
    {
        $this->fakeMyFatoorah();
        $cancelled = Order::factory()->pendingPayment()->withStatus(OrderStatus::Cancelled)->create();
        $waiting = Order::factory()->pendingPayment()->create();

        $this->withSession(['payment.order' => $cancelled->number])->get('/en-KW/checkout/apple-pay/'.$cancelled->number)->assertNotFound();

        config(['myfatoorah.apple_pay_on_site' => false]);
        $this->app->forgetScopedInstances();
        $this->withSession(['payment.order' => $waiting->number])->get('/en-KW/checkout/apple-pay/'.$waiting->number)->assertNotFound();
    }

    // ---------------------------------------------------------- the answer

    public function test_a_payment_my_fatoorah_confirms_settles_the_order_and_sends_the_shopper_on_with_the_basket_cleared(): void
    {
        [$order, $payment] = $this->waitingOrder();
        $this->sessionPaid($payment);

        $response = $this->withSession(['payment.order' => $order->number, 'cart.items' => $this->basket()['cart.items']])
            ->postJson('/en-KW/checkout/apple-pay/'.$order->number.'/confirm');

        $response->assertOk()->assertExactJson(['result' => 'paid', 'redirect' => url('/en-KW/checkout/thanks/'.$order->number)]);
        $this->assertSame([PaymentStatus::Paid, OrderStatus::New], [$order->refresh()->payment_status, $order->status]);
        $this->assertSame(PaymentState::Paid, $payment->refresh()->state);
        $response->assertSessionMissing('cart.items')->assertSessionMissing('payment.order');
    }

    public function test_nothing_the_browser_says_decides_anything_only_my_fatoorahs_answer_about_our_own_session(): void
    {
        [$order, $payment] = $this->waitingOrder();
        $this->fakeMyFatoorah([self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails())]);

        $response = $this->withSession(['payment.order' => $order->number])
            ->postJson('/en-KW/checkout/apple-pay/'.$order->number.'/confirm', [
                'isSuccess' => true, 'paymentCompleted' => true, 'paymentId' => self::PAYMENT_ID, 'sessionId' => 'KWT-SOMEONE-ELSES',
            ]);

        $response->assertOk()->assertExactJson(['result' => 'unpaid']);
        $this->assertSame(PaymentStatus::Unpaid, $order->refresh()->payment_status);
        $this->assertSame(PaymentState::Pending, $payment->refresh()->state);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'KWT-SOMEONE-ELSES') || str_contains($request->url(), self::PAYMENT_ID));
    }

    public function test_a_declined_payment_is_reported_as_failed_and_the_order_keeps_waiting(): void
    {
        [$order, $payment] = $this->waitingOrder();
        $this->fakeMyFatoorah([
            self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response($this->sessionDetails('paid', ['TransactionResult.Transaction.Status' => 'FAILED'])),
            self::MF.'/v3/payments/'.self::PAYMENT_ID => Http::response($this->paymentDetails($payment->reference, [
                'Invoice.Status' => 'PENDING', 'Transaction.Status' => 'FAILED',
            ])),
        ]);

        $response = $this->withSession(['payment.order' => $order->number])->postJson('/en-KW/checkout/apple-pay/'.$order->number.'/confirm');

        $response->assertOk()->assertJsonPath('result', 'failed');
        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
    }

    public function test_when_my_fatoorah_cannot_be_asked_the_shopper_is_told_it_is_being_confirmed_not_that_it_failed(): void
    {
        [$order] = $this->waitingOrder();
        $this->fakeMyFatoorah([self::MF.'/v3/sessions/KWT-SESSION-1' => Http::response(['IsSuccess' => false], 503)]);

        $this->withSession(['payment.order' => $order->number])->postJson('/en-KW/checkout/apple-pay/'.$order->number.'/confirm')
            ->assertOk()->assertExactJson(['result' => 'pending', 'redirect' => url('/en-KW/checkout/pending/'.$order->number)]);
    }

    public function test_only_the_shopper_who_placed_the_order_can_ask_and_only_for_an_order_with_an_apple_pay_attempt(): void
    {
        [$order] = $this->waitingOrder();
        $plain = Order::factory()->pendingPayment()->create();
        $this->fakeMyFatoorah();

        $this->postJson('/en-KW/checkout/apple-pay/'.$order->number.'/confirm')->assertNotFound();
        $this->withSession(['payment.order' => $plain->number])->postJson('/en-KW/checkout/apple-pay/'.$plain->number.'/confirm')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_asking_again_after_the_webhook_already_settled_the_order_still_reports_it_paid(): void
    {
        [$order, $payment] = $this->waitingOrder();
        $this->sessionPaid($payment);
        $this->app->make(PaymentService::class)->confirm(self::PAYMENT_ID);
        $this->assertSame(OrderStatus::New, $order->refresh()->status);

        $this->withSession(['payment.order' => $order->number])->postJson('/en-KW/checkout/apple-pay/'.$order->number.'/confirm')
            ->assertOk()->assertJsonPath('result', 'paid');
    }

    // ------------------------------------------------- the verification file

    public function test_the_domain_verification_file_is_served_exactly_as_it_was_saved_and_is_a_404_before_that(): void
    {
        $this->get('/.well-known/apple-developer-merchantid-domain-association')->assertNotFound();

        // What the first request read is remembered for the length of one request, as in the real thing.
        $this->app->forgetScopedInstances();
        GatewayCredential::factory()->create(['apple_pay_domain_file' => "7B2266\n6F6F626172"]);

        $response = $this->get('/.well-known/apple-developer-merchantid-domain-association');

        $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame("7B2266\n6F6F626172", $response->getContent());
    }
}
