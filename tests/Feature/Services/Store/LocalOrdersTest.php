<?php

namespace Tests\Feature\Services\Store;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderPlaced;
use App\Models\Customer;
use App\Models\DeliveryArea;
use App\Models\DeliveryCity;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductQuantityTier;
use App\Models\ServiceAddon;
use App\Models\Voucher;
use App\Services\Store\LocalCart;
use App\Services\Store\LocalOrders;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesMyFatoorah;
use Tests\Concerns\IsolatesSettings;
use Tests\TestCase;

class LocalOrdersTest extends TestCase
{
    use FakesMyFatoorah, IsolatesSettings, LazilyRefreshDatabase;

    private DeliveryArea $area;

    private function orders(): LocalOrders
    {
        return $this->app->make(LocalOrders::class);
    }

    /** Fakes only the create call, for a test that already faked the methods list its own way. */
    private function fakeMyFatoorahCreate(): void
    {
        Http::fake([self::MF.'/v3/payments' => Http::response($this->fixture('myfatoorah/create-payment'), 201)]);
    }

    private function cart(): LocalCart
    {
        return $this->app->make(LocalCart::class);
    }

    /**
     * What the checkout form hands over once validated.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function checkout(array $overrides = []): array
    {
        $this->area ??= DeliveryArea::factory()->for(DeliveryCity::factory(), 'city')->create(['fee_fils' => 2_000]);

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
            'voucher' => null,
            'addons' => [],
        ];
    }

    // ----------------------------------------------------------- the happy path

    public function test_a_cash_on_delivery_order_is_created_priced_and_confirmed(): void
    {
        Event::fake([OrderPlaced::class]);
        $discounted = Product::factory()->priced(35_000)->fixedDiscount(16_000)->create(['name_en' => 'Albustan', 'name_ar' => 'البستان', 'sku' => 'ALB-1']);
        $this->cart()->add((string) $discounted->id, 2);

        $result = $this->orders()->place($this->checkout());

        $order = Order::query()->with('items')->firstOrFail();
        $this->assertSame(['ok' => true, 'kind' => 'confirmed', 'paymentUrl' => null, 'orderId' => 'DS-100001', 'message' => null, 'clearCart' => true], $result);
        $this->assertSame('DS-100001', $order->number);
        $this->assertSame([OrderStatus::New, PaymentStatus::Unpaid, 'cod'], [$order->status, $order->payment_status, $order->payment_method]);
        $this->assertSame([38_000, 0, 2_000, 0, 0, 40_000], [
            $order->subtotal_fils, $order->discount_fils, $order->delivery_fee_fils,
            $order->addons_total_fils, $order->cod_fee_fils, $order->total_fils,
        ]);
        $this->assertSame(['Sara Al-Ahmad', 'sara@example.com', '+96551234567'], [$order->customer_name, $order->customer_email, $order->customer_phone]);
        $this->assertCount(1, $order->items);
        $this->assertSame(['البستان', 'Albustan', 'ALB-1', 2, 19_000, 38_000], [
            $order->items[0]->name_ar, $order->items[0]->name_en, $order->items[0]->sku,
            $order->items[0]->quantity, $order->items[0]->unit_price_fils, $order->items[0]->total_fils,
        ]);
        Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event) => $event->order->is($order));
    }

    public function test_the_order_records_where_it_goes_as_text_that_survives_the_area_being_renamed(): void
    {
        $city = DeliveryCity::factory()->create(['name_en' => 'Hawalli', 'name_ar' => 'حولي']);
        $this->area = DeliveryArea::factory()->for($city, 'city')->create(['name_en' => 'Salmiya', 'name_ar' => 'السالمية', 'fee_fils' => 2_000]);
        $this->cart()->add((string) Product::factory()->create()->id);

        $this->orders()->place($this->checkout(['block' => '4', 'street' => 'Street 12', 'avenue' => '3', 'building' => '7', 'floor' => '2', 'apartment' => '9']));
        $this->area->update(['name_en' => 'Renamed', 'name_ar' => 'اسم جديد']);

        $order = Order::query()->firstOrFail();
        $this->assertSame(['Hawalli', 'Salmiya', 'حولي', 'السالمية'], [$order->city_name_en, $order->area_name_en, $order->city_name_ar, $order->area_name_ar]);
        $this->assertSame(['4', 'Street 12', '3', '7', '2', '9'], [$order->block, $order->street, $order->avenue, $order->building, $order->floor, $order->apartment]);
        $this->assertSame($this->area->id, $order->delivery_area_id);
    }

    public function test_it_is_charged_with_the_voucher_add_ons_and_cod_fee_the_engine_works_out(): void
    {
        $this->setting(['commerce.cod_fee_fils' => 500]);
        $product = Product::factory()->priced(50_000)->create();
        $wrap = ServiceAddon::factory()->create(['price_fils' => 1_500, 'name_en' => 'Gift wrapping']);
        Voucher::factory()->percentage(10)->create(['code' => 'TEN']);
        $this->cart()->add((string) $product->id);

        $this->orders()->place($this->checkout(['voucher' => 'ten', 'addons' => [(string) $wrap->id]]));

        $order = Order::query()->firstOrFail();
        // 50.000 - 5.000 (10%) + 2.000 delivery + 1.500 add-on + 0.500 cod fee.
        $this->assertSame([50_000, 5_000, 2_000, 1_500, 500, 49_000, 'TEN'], [
            $order->subtotal_fils, $order->discount_fils, $order->delivery_fee_fils,
            $order->addons_total_fils, $order->cod_fee_fils, $order->total_fils, $order->voucher_code,
        ]);
        $this->assertSame('Gift wrapping', $order->addons[0]['name_en']);
    }

    public function test_the_chosen_options_are_kept_as_names_and_prices_on_the_order_line(): void
    {
        $product = Product::factory()->priced(0)->create();
        $group = OptionGroup::factory()->for($product)->create(['name_en' => 'Weight', 'name_ar' => 'الوزن']);
        $value = OptionValue::factory()->for($group, 'group')->priced(60_000)->create(['name_en' => '2 tola', 'name_ar' => '2 تولة']);
        $this->cart()->add((string) $product->id, 1, null, [['optionId' => (string) $group->id, 'values' => [['valueId' => (string) $value->id, 'quantity' => 1]]]]);

        $this->orders()->place($this->checkout());

        $item = Order::query()->firstOrFail()->items[0];
        $this->assertSame(60_000, $item->unit_price_fils);
        $this->assertSame([['group' => ['ar' => 'الوزن', 'en' => 'Weight'], 'values' => [['name' => ['ar' => '2 تولة', 'en' => '2 tola'], 'quantity' => 1, 'price_fils' => 60_000]]]], $item->options);
    }

    public function test_a_quantity_tier_discount_is_in_the_line_total_that_is_stored(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        ProductQuantityTier::factory()->for($product)->from(3)->fixed(10_000)->create();
        $this->cart()->add((string) $product->id, 3);

        $this->orders()->place($this->checkout());

        $order = Order::query()->with('items')->firstOrFail();
        $this->assertSame([20_000, 22_000], [$order->subtotal_fils, $order->total_fils]);
        $this->assertSame([10_000, 20_000], [$order->items[0]->unit_price_fils, $order->items[0]->total_fils]);
    }

    public function test_the_first_status_is_recorded_and_an_account_holder_keeps_the_order_and_the_address(): void
    {
        $customer = Customer::factory()->create(['email' => 'account@example.com']);
        $this->actingAs($customer, 'customer');
        $this->cart()->add((string) Product::factory()->create()->id);

        $this->orders()->place($this->checkout(['email' => null]));

        $order = Order::query()->firstOrFail();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame('account@example.com', $order->customer_email);
        $this->assertSame([[null, OrderStatus::New]], $order->statusChanges->map(fn ($change) => [$change->from_status, $change->to_status])->all());
        $this->assertDatabaseHas('customer_addresses', ['customer_id' => $customer->id, 'delivery_area_id' => $this->area->id, 'street' => 'Street 12']);
    }

    public function test_ordering_to_the_same_address_twice_remembers_it_once(): void
    {
        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer');
        $product = Product::factory()->create();

        $this->cart()->add((string) $product->id);
        $this->orders()->place($this->checkout());
        $this->cart()->clear();
        $this->cart()->add((string) $product->id);
        $this->orders()->place($this->checkout());

        $this->assertSame(1, $customer->addresses()->count());
        $this->assertSame(2, Order::query()->count());
    }

    public function test_a_guest_order_has_no_account(): void
    {
        $this->cart()->add((string) Product::factory()->create()->id);

        $this->orders()->place($this->checkout());

        $this->assertNull(Order::query()->firstOrFail()->customer_id);
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_an_online_order_goes_to_the_payment_page_holds_its_stock_and_keeps_the_basket_until_it_is_paid(): void
    {
        Event::fake([OrderPlaced::class]);
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah();
        $product = Product::factory()->withStock(5)->create();
        $this->cart()->add((string) $product->id, 2);

        $result = $this->orders()->place($this->checkout(['payment' => 'online', 'paymentMethod' => 'KNET']));

        $order = Order::query()->firstOrFail();
        $this->assertSame([true, 'redirect', false, 'https://demo.MyFatoorah.com/KWT/ie/050754719614810863-ce9138bf', $order->number], [
            $result['ok'], $result['kind'], $result['clearCart'], $result['paymentUrl'], $result['orderId'],
        ]);
        $this->assertSame([OrderStatus::PendingPayment, PaymentStatus::Unpaid, 'online'], [$order->status, $order->payment_status, $order->payment_method]);
        $this->assertSame(3, $product->fresh()->stock);
        $payment = $order->payments()->firstOrFail();
        $this->assertSame(['KNET', $order->total_fils], [$payment->method, $payment->amount_fils]);
        $this->assertSame($order->number, session('payment.order'));
        // The shop hears about the order when it is paid, not when it is started.
        Event::assertNotDispatched(OrderPlaced::class);
    }

    public function test_an_online_order_is_refused_before_anything_is_reserved_while_online_payment_is_not_available(): void
    {
        Http::preventStrayRequests();
        $product = Product::factory()->withStock(5)->create();
        $this->cart()->add((string) $product->id);

        $result = $this->orders()->place($this->checkout(['payment' => 'online', 'paymentMethod' => 'KNET']));

        $this->assertFalse($result['ok']);
        $this->assertSame('We could not start the online payment right now. Please try again, or choose cash on delivery.', $result['message']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_a_payment_method_the_shop_does_not_offer_is_refused(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah();
        $this->cart()->add((string) Product::factory()->create()->id);

        $result = $this->orders()->place($this->checkout(['payment' => 'online', 'paymentMethod' => 'MADA']));

        $this->assertFalse($result['ok']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_the_plain_online_choice_sends_no_method_so_my_fatoorahs_page_offers_everything(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah([self::MF.'/v3/payment-methods' => Http::response(['IsSuccess' => false, 'Message' => 'no'], 401)]);
        $this->fakeMyFatoorahCreate();
        $this->cart()->add((string) Product::factory()->create()->id);

        $result = $this->orders()->place($this->checkout(['payment' => 'online', 'paymentMethod' => 'all']));

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/payments') && ! array_key_exists('PaymentMethod', $request->data()));
    }

    public function test_when_the_payment_page_cannot_be_created_the_order_is_released_and_its_stock_comes_back(): void
    {
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah([self::MF.'/v3/payments' => Http::response(['IsSuccess' => false, 'Message' => 'down'], 503)]);
        $product = Product::factory()->withStock(5)->create();
        $this->cart()->add((string) $product->id, 2);

        $result = $this->orders()->place($this->checkout(['payment' => 'online', 'paymentMethod' => 'KNET']));

        $order = Order::query()->firstOrFail();
        $this->assertFalse($result['ok']);
        $this->assertSame('We could not start the online payment right now. Please try again, or choose cash on delivery.', $result['message']);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertNull(session('payment.order'));
    }

    public function test_an_online_order_a_voucher_brings_to_nothing_needs_no_payment_page(): void
    {
        Event::fake([OrderPlaced::class]);
        $this->configureMyFatoorah();
        $this->fakeMyFatoorah();
        Voucher::factory()->percentage(100)->create(['code' => 'FREE']);
        $this->area = DeliveryArea::factory()->for(DeliveryCity::factory(), 'city')->create(['fee_fils' => 0]);
        $this->cart()->add((string) Product::factory()->priced(10_000)->create()->id);

        $result = $this->orders()->place($this->checkout(['payment' => 'online', 'paymentMethod' => 'KNET', 'voucher' => 'FREE']));

        $order = Order::query()->firstOrFail();
        $this->assertSame(['confirmed', true, 0], [$result['kind'], $result['clearCart'], $order->total_fils]);
        $this->assertSame([OrderStatus::New, PaymentStatus::Paid], [$order->status, $order->payment_status]);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v3/payments'));
        Event::assertDispatchedTimes(OrderPlaced::class, 1);
    }

    public function test_the_online_methods_offered_are_those_enabled_and_none_while_payment_is_off(): void
    {
        $this->assertSame([], $this->orders()->paymentMethods());

        $this->configureMyFatoorah();
        $this->fakeMyFatoorah();
        app()->setLocale('en');

        $this->assertSame(['KNET', 'CARD', 'APPLE_PAY'], array_column($this->orders()->paymentMethods(), 'id'));
    }

    // ------------------------------------------------------------------- stock

    public function test_stock_is_taken_for_every_line_including_the_same_product_on_two_lines(): void
    {
        $product = Product::factory()->priced(0)->withStock(10)->create();
        $group = OptionGroup::factory()->for($product)->create();
        $small = OptionValue::factory()->for($group, 'group')->priced(1_000)->create();
        $large = OptionValue::factory()->for($group, 'group')->priced(2_000)->create();
        $pick = fn (OptionValue $v) => [['optionId' => (string) $group->id, 'values' => [['valueId' => (string) $v->id, 'quantity' => 1]]]];
        $this->cart()->add((string) $product->id, 3, null, $pick($small));
        $this->cart()->add((string) $product->id, 4, null, $pick($large));

        $this->orders()->place($this->checkout());

        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_the_last_bottle_goes_to_one_shopper_only(): void
    {
        $product = Product::factory()->withStock(1)->create();
        $this->cart()->add((string) $product->id);
        $first = $this->orders()->place($this->checkout());

        // A second shopper reaches checkout holding the same basket, after the stock has gone.
        $this->cart()->clear();
        $this->cart()->add((string) $product->id);
        $second = $this->orders()->place($this->checkout());

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('Out of stock', $second['message']);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_failure_part_way_through_taking_stock_rolls_everything_back(): void
    {
        $plenty = Product::factory()->withStock(10)->create();
        $scarce = Product::factory()->withStock(5)->create();
        $this->cart()->add((string) $plenty->id, 2);
        $this->cart()->add((string) $scarce->id, 1);
        // Pricing sees enough of both. Then, the moment the first product's
        // stock is taken, another shopper's order empties the second.
        $emptied = false;
        DB::listen(function ($query) use (&$emptied, $scarce): void {
            if (! $emptied && str_contains($query->sql, 'update "products" set "stock" = "stock" - ')) {
                $emptied = true;
                DB::table('products')->where('id', $scarce->id)->update(['stock' => 0]);
            }
        });

        $result = $this->orders()->place($this->checkout());

        $this->assertTrue($emptied);
        $this->assertFalse($result['ok']);
        $this->assertSame('Out of stock', $result['message']);
        // The first product's stock came back with the rolled-back transaction.
        $this->assertSame(10, $plenty->fresh()->stock);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    // ---------------------------------------------------------------- failures

    /**
     * @return array<string, array{string}>
     */
    public static function refusals(): array
    {
        return [
            'an empty basket' => ['empty'],
            'a product that has gone' => ['gone'],
            'an area that is not served' => ['bad-area'],
            'a basket below the minimum order' => ['below-minimum'],
            'a voucher that is not valid' => ['bad-voucher'],
            'cash on delivery a product does not allow' => ['cod-refused'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_an_order_that_cannot_be_placed_is_refused_with_a_reason_and_leaves_nothing_behind(string $case): void
    {
        Event::fake([OrderPlaced::class]);
        $product = Product::factory()->priced(10_000)->withStock(5)->create();
        $input = $this->checkout();

        match ($case) {
            'empty' => null,
            'gone' => $this->cart()->add('999999'),
            'bad-area' => [$this->cart()->add((string) $product->id), $this->area->update(['is_active' => false])],
            'below-minimum' => [$this->cart()->add((string) $product->id), $this->setting(['commerce.minimum_order_fils' => 50_000])],
            'bad-voucher' => [$this->cart()->add((string) $product->id), $input['voucher'] = 'NOPE'],
            'cod-refused' => [$product->update(['cod_enabled' => false]), $this->cart()->add((string) $product->id)],
        };

        $result = $this->orders()->place($input);

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['kind']);
        $this->assertNotEmpty($result['message']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $product->fresh()->stock);
        Event::assertNotDispatched(OrderPlaced::class);
    }

    public function test_the_reason_given_is_the_one_the_shopper_can_act_on(): void
    {
        $this->cart()->add((string) Product::factory()->priced(10_000)->create()->id);
        $this->setting(['commerce.minimum_order_fils' => 50_000]);

        $result = $this->orders()->place($this->checkout());

        $this->assertSame('Minimum order is 50 KWD', $result['message']);
    }

    // ---------------------------------------------------------------- locations

    public function test_delivery_locations_list_active_cities_with_their_active_areas_in_the_shoppers_language(): void
    {
        $open = DeliveryCity::factory()->create(['name_ar' => 'حولي', 'name_en' => 'Hawalli', 'sort_order' => 1]);
        DeliveryArea::factory()->for($open, 'city')->create(['name_ar' => 'السالمية', 'name_en' => 'Salmiya']);
        DeliveryArea::factory()->for($open, 'city')->create(['name_ar' => 'الجابرية', 'name_en' => 'Jabriya']);
        DeliveryArea::factory()->for($open, 'city')->create(['name_en' => 'Closed', 'is_active' => false]);
        $closedCity = DeliveryCity::factory()->create(['is_active' => false]);
        DeliveryArea::factory()->for($closedCity, 'city')->create();
        DeliveryCity::factory()->create(['name_en' => 'Empty city', 'sort_order' => 2]);

        app()->setLocale('en');
        $locations = $this->orders()->deliveryLocations();

        $this->assertCount(1, $locations);
        $this->assertSame('Hawalli', $locations[0]['name']);
        $this->assertSame(['Jabriya', 'Salmiya'], array_column($locations[0]['areas'], 'name'));
    }

    public function test_service_add_ons_are_offered_only_when_there_is_an_active_one(): void
    {
        $this->assertFalse($this->orders()->addonsEnabled());
        $this->assertSame([], $this->orders()->serviceAddons());

        ServiceAddon::factory()->create(['name_en' => 'Gift wrapping', 'price_fils' => 1_500]);
        ServiceAddon::factory()->create(['is_active' => false]);
        app()->setLocale('en');

        $this->assertTrue($this->orders()->addonsEnabled());
        $this->assertSame(['Gift wrapping'], array_column($this->orders()->serviceAddons(), 'name'));
        $this->assertSame(1.5, $this->orders()->serviceAddons()[0]['price']);
    }

    // ------------------------------------------------------------------ history

    public function test_a_customer_sees_only_their_own_orders_newest_first_including_one_awaiting_payment_with_the_way_to_pay_it(): void
    {
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();
        $older = Order::factory()->forCustomer($customer)->create();
        $awaiting = Order::factory()->forCustomer($customer)->pendingPayment()->create();
        $newer = Order::factory()->forCustomer($customer)->create();
        Order::factory()->forCustomer($other)->create();
        $this->actingAs($customer, 'customer');

        $orders = $this->orders()->myOrders();

        $this->assertSame([$newer->number, $awaiting->number, $older->number], array_column($orders, 'orderNumber'));
        $this->assertNull($orders[0]['payUrl']);
        $this->assertStringEndsWith('/checkout/pay/'.$awaiting->number, $orders[1]['payUrl']);
    }

    public function test_a_customer_can_open_their_order_by_number_or_id_but_nobody_elses(): void
    {
        $customer = Customer::factory()->create();
        $mine = Order::factory()->forCustomer($customer)->create();
        $theirs = Order::factory()->forCustomer(Customer::factory()->create())->create();
        $this->actingAs($customer, 'customer');

        $this->assertSame($mine->number, $this->orders()->findOrder($mine->number)['orderNumber']);
        $this->assertSame($mine->number, $this->orders()->findOrder((string) $mine->id)['orderNumber']);
        $this->assertNull($this->orders()->findOrder($theirs->number));
        $this->assertNull($this->orders()->findOrder((string) $theirs->id));
        $this->assertNull($this->orders()->findOrder('DS-999999'));
    }

    public function test_a_guest_has_no_order_history(): void
    {
        $order = Order::factory()->create();

        $this->assertSame([], $this->orders()->myOrders());
        $this->assertNull($this->orders()->findOrder($order->number));
    }

    public function test_an_order_reads_as_the_account_pages_expect_in_the_shoppers_language(): void
    {
        $customer = Customer::factory()->create();
        $order = Order::factory()->forCustomer($customer)->totalling(38_000, 2_000)->withStatus(OrderStatus::OutForDelivery)->create();
        $order->items()->create(['name_ar' => 'البستان', 'name_en' => 'Albustan', 'quantity' => 2, 'unit_price_fils' => 19_000, 'total_fils' => 38_000, 'image' => 'uploads/catalog/products/a.png']);
        $this->actingAs($customer, 'customer');
        app()->setLocale('ar');

        $presented = $this->orders()->findOrder($order->number);

        $this->assertSame(['في الطريق إليك', 'pending', 38, 2, 40], [
            $presented['status'], $presented['statusTone'], $presented['subTotal'], $presented['deliveryFees'], $presented['total'],
        ]);
        $this->assertSame('البستان', $presented['items'][0]['productId']['title']['ar']);
        $this->assertSame([2, 38], [$presented['items'][0]['quantity'], $presented['items'][0]['totalPriceAfterDiscount']]);
        $this->assertStringEndsWith('/uploads/catalog/products/a.png', $presented['items'][0]['productId']['mainImage']);
    }
}
