<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryCity;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesMyFatoorah;
use Tests\Concerns\IsolatesSettings;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

class CheckoutControllerTest extends TestCase
{
    use FakesMyFatoorah, IsolatesSettings, LazilyRefreshDatabase, UsesLocalStore;

    private DeliveryArea $area;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = DeliveryArea::factory()->for(DeliveryCity::factory(), 'city')->create(['fee_fils' => 2_000]);
        $this->product = Product::factory()->priced(35_000)->fixedDiscount(16_000)->create(['name_en' => 'Albustan']);
    }

    /**
     * @return array<string,mixed>
     */
    private function basket(int $quantity = 2): array
    {
        return ['cart.items' => ['line' => [
            'productId' => (string) $this->product->id,
            'quantity' => $quantity,
            'varientId' => null,
            'options' => [],
        ]]];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'fullName' => 'Sara Al-Ahmad',
            'email' => 'sara@example.com',
            'phone' => '51234567',
            'city' => (string) $this->area->delivery_city_id,
            'area' => (string) $this->area->id,
            'block' => '4',
            'street' => 'Street 12',
            'building' => '7',
            'payment' => 'cod',
        ];
    }

    public function test_the_checkout_page_shows_the_basket_total_the_local_areas_and_only_cash_on_delivery(): void
    {
        $response = $this->withSession($this->basket())->get('/en-KW/checkout');

        $response->assertOk()
            ->assertSee('Albustan')
            ->assertSee('38 KWD')
            ->assertSee('value="'.$this->area->delivery_city_id.'"', false)
            ->assertSee('Cash on delivery')
            ->assertDontSee('Pay online');
    }

    public function test_an_empty_basket_is_sent_back_to_the_cart(): void
    {
        $this->get('/en-KW/checkout')->assertRedirect('/en-KW/cart');
    }

    public function test_placing_the_order_creates_it_clears_the_basket_and_lands_on_the_thank_you_page(): void
    {
        $response = $this->withSession($this->basket())->post('/en-KW/checkout', $this->form());

        $response->assertRedirect('/en-KW/checkout/thanks/DS-100001');
        $response->assertSessionMissing('cart.items');
        $order = Order::query()->firstOrFail();
        $this->assertSame([OrderStatus::New, 40_000, 'cod'], [$order->status, $order->total_fils, $order->payment_method]);
        $this->get('/en-KW/checkout/thanks/DS-100001')->assertOk()->assertSee('DS-100001');
    }

    public function test_a_voucher_applied_in_the_cart_is_charged_at_checkout(): void
    {
        Voucher::factory()->fixed(3_000)->create(['code' => 'SAVE3']);

        $this->withSession($this->basket() + ['cart.voucher' => 'SAVE3'])->post('/en-KW/checkout', $this->form());

        $order = Order::query()->firstOrFail();
        $this->assertSame([3_000, 37_000, 'SAVE3'], [$order->discount_fils, $order->total_fils, $order->voucher_code]);
    }

    public function test_the_area_must_belong_to_the_chosen_governorate(): void
    {
        $otherArea = DeliveryArea::factory()->for(DeliveryCity::factory(), 'city')->create();

        $response = $this->withSession($this->basket())->post('/en-KW/checkout', $this->form(['area' => (string) $otherArea->id]));

        $response->assertSessionHasErrors('area');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_required_fields_and_the_phone_format_are_checked(): void
    {
        $response = $this->withSession($this->basket())->post('/en-KW/checkout', $this->form(['fullName' => '', 'phone' => '012345']));

        $response->assertSessionHasErrors(['fullName', 'phone']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_paying_online_is_refused_until_a_payment_gateway_is_connected(): void
    {
        $response = $this->withSession($this->basket())->post('/en-KW/checkout', $this->form(['payment' => 'online', 'paymentMethod' => 'knet']));

        $response->assertSessionHasErrors('paymentMethod');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cash_on_delivery_can_be_switched_off_for_the_whole_shop(): void
    {
        $this->setting(['checkout.cod' => false]);

        $page = $this->withSession($this->basket())->get('/en-KW/checkout');
        $post = $this->withSession($this->basket())->post('/en-KW/checkout', $this->form());

        $page->assertOk()->assertSee('No payment method is available right now');
        $post->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_an_order_the_shop_cannot_fulfil_comes_back_to_the_form_with_the_reason_and_keeps_the_basket(): void
    {
        $this->product->update(['track_stock' => true, 'stock' => 1]);

        $response = $this->withSession($this->basket(2))->from('/en-KW/checkout')->post('/en-KW/checkout', $this->form());

        $response->assertRedirect('/en-KW/checkout')->assertSessionHasErrors(['checkout' => 'Only 1 left in stock']);
        $response->assertSessionHas('cart.items');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_a_signed_in_customer_finds_the_order_in_their_account(): void
    {
        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer');
        $this->withSession($this->basket())->post('/en-KW/checkout', $this->form());

        $this->get('/en-KW/account/orders')->assertOk()->assertSee('DS-100001')->assertSee('Received')->assertSee('40 KWD');
        $this->get('/en-KW/account/orders/DS-100001')->assertOk()->assertSee('Albustan')->assertSee('38 KWD');
    }

    public function test_one_customer_cannot_open_anothers_order(): void
    {
        $order = Order::factory()->forCustomer(Customer::factory()->create())->create();
        $this->actingAs(Customer::factory()->create(), 'customer');

        $this->get('/en-KW/account/orders/'.$order->number)->assertNotFound();
    }

    public function test_a_guest_is_asked_to_sign_in_before_seeing_orders(): void
    {
        $this->get('/en-KW/account/orders')->assertRedirect('/en-KW/login');
    }

    // ------------------------------------------------------- paying online

    private function onlineOn(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah();
    }

    public function test_the_checkout_page_offers_the_enabled_online_methods_beside_cash_on_delivery(): void
    {
        $this->onlineOn();

        $response = $this->withSession($this->basket())->get('/en-KW/checkout');

        $response->assertOk()
            ->assertSee('Pay online')
            ->assertSee('Cash on delivery')
            ->assertSee('KNET')
            ->assertSee('Card (Visa / Mastercard)')
            ->assertSee('Apple Pay')
            ->assertDontSee('MADA');
    }

    public function test_the_payment_step_shows_a_tile_for_each_method_and_says_where_the_payment_happens(): void
    {
        $this->onlineOn();

        $this->withSession($this->basket())->get('/en-KW/checkout')
            ->assertSee('pay-method__icon', false)
            ->assertSee('You complete payment on a secure payment page. Card details are never entered on this site.')
            ->assertSee('Pay when your order arrives');
    }

    public function test_the_secure_payment_note_is_not_shown_when_only_cash_is_offered(): void
    {
        $this->withSession($this->basket())->get('/en-KW/checkout')
            ->assertDontSee('secure payment page');
    }

    public function test_paying_online_sends_the_shopper_to_the_payment_page_and_keeps_the_basket(): void
    {
        $this->onlineOn();

        $response = $this->withSession($this->basket())->post('/en-KW/checkout', $this->form(['payment' => 'online', 'paymentMethod' => 'KNET']));

        $response->assertRedirect('https://demo.MyFatoorah.com/KWT/ie/050754719614810863-ce9138bf')
            ->assertSessionHas('cart.items')
            ->assertSessionHas('payment.order', 'DS-100001');
        $order = Order::query()->firstOrFail();
        $this->assertSame([OrderStatus::PendingPayment, 40_000, 'KNET'], [$order->status, $order->total_fils, $order->payments()->firstOrFail()->method]);
    }

    public function test_a_method_the_shop_does_not_offer_is_refused_at_the_form(): void
    {
        $this->onlineOn();

        $response = $this->withSession($this->basket())->post('/en-KW/checkout', $this->form(['payment' => 'online', 'paymentMethod' => 'MADA']));

        $response->assertSessionHasErrors('paymentMethod');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_when_the_payment_page_cannot_be_opened_the_shopper_comes_back_to_the_form_and_the_basket_is_kept(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::response(['IsSuccess' => false, 'Message' => 'down'], 503)]);

        $response = $this->withSession($this->basket())->from('/en-KW/checkout')
            ->post('/en-KW/checkout', $this->form(['payment' => 'online', 'paymentMethod' => 'KNET']));

        $response->assertRedirect('/en-KW/checkout')
            ->assertSessionHasErrors(['checkout' => 'We could not start the online payment right now. Please try again, or choose cash on delivery.'])
            ->assertSessionHas('cart.items');
        $this->assertSame(OrderStatus::Cancelled, Order::query()->firstOrFail()->status);
    }

    public function test_the_shopper_can_go_back_to_the_payment_page_for_the_order_they_started_without_a_second_invoice(): void
    {
        $this->onlineOn();
        $this->withSession($this->basket())->post('/en-KW/checkout', $this->form(['payment' => 'online', 'paymentMethod' => 'KNET']));
        Http::fake();

        $response = $this->withSession(['payment.order' => 'DS-100001'])->get('/en-KW/checkout/pay/DS-100001');

        $response->assertRedirect('https://demo.MyFatoorah.com/KWT/ie/050754719614810863-ce9138bf');
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_customer_can_pay_an_order_they_left_waiting_from_their_account(): void
    {
        $this->onlineOn();
        $customer = Customer::factory()->create();
        $order = Order::factory()->forCustomer($customer)->pendingPayment()->create(['total_fils' => 40_000]);
        $this->actingAs($customer, 'customer');

        $this->get('/en-KW/account/orders/'.$order->number)->assertOk()->assertSee('Pay now')->assertSee('Awaiting payment');
        $this->get('/en-KW/checkout/pay/'.$order->number)->assertRedirect('https://demo.MyFatoorah.com/KWT/ie/050754719614810863-ce9138bf');
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_nobody_else_can_open_the_payment_page_of_an_order(): void
    {
        $this->onlineOn();
        $order = Order::factory()->forCustomer(Customer::factory()->create())->pendingPayment()->create();

        $this->get('/en-KW/checkout/pay/'.$order->number)->assertNotFound();
        $this->withSession(['payment.order' => 'DS-999999'])->get('/en-KW/checkout/pay/'.$order->number)->assertNotFound();
        $this->actingAs(Customer::factory()->create(), 'customer')->get('/en-KW/checkout/pay/'.$order->number)->assertNotFound();
    }

    public function test_an_order_that_is_already_paid_or_cancelled_has_no_payment_page(): void
    {
        $this->onlineOn();
        $paid = Order::factory()->paid()->create();
        $cancelled = Order::factory()->pendingPayment()->withStatus(OrderStatus::Cancelled)->create();

        $this->withSession(['payment.order' => $paid->number])->get('/en-KW/checkout/pay/'.$paid->number)->assertNotFound();
        $this->withSession(['payment.order' => $cancelled->number])->get('/en-KW/checkout/pay/'.$cancelled->number)->assertNotFound();
    }

    public function test_the_way_back_to_payment_is_shown_to_the_orders_owner_and_to_nobody_else(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $this->withSession(['payment.order' => $order->number])->get('/en-KW/checkout/failed?order='.$order->number)
            ->assertOk()->assertSee('Try the payment again');
        $this->flushSession();
        $this->get('/en-KW/checkout/failed?order='.$order->number)->assertOk()->assertDontSee('Try the payment again');
    }

    public function test_when_the_payment_page_cannot_be_reopened_the_shopper_lands_on_the_failed_page(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::response(['IsSuccess' => false, 'Message' => 'down'], 503)]);
        $order = Order::factory()->pendingPayment()->create();
        Payment::factory()->create(['order_id' => $order->id, 'expires_at' => now()->subMinute()]);

        $this->withSession(['payment.order' => $order->number])->get('/en-KW/checkout/pay/'.$order->number)
            ->assertRedirect('/en-KW/checkout/failed');
    }
}
