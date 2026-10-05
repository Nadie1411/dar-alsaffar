<?php

namespace Tests\Feature\Services\Store\Pricing;

use App\Enums\OrderStatus;
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
use App\Services\Store\Pricing\PricedCart;
use App\Services\Store\Pricing\PricingContext;
use App\Services\Store\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\IsolatesSettings;
use Tests\TestCase;

class PricingEngineTest extends TestCase
{
    use IsolatesSettings, LazilyRefreshDatabase;

    /**
     * @param  array<string,array<string,mixed>>  $basket
     */
    private function price(array $basket, ?PricingContext $context = null): PricedCart
    {
        return $this->app->make(PricingEngine::class)->price($basket, $context ?? new PricingContext);
    }

    /**
     * @param  array<int,array<string,mixed>>  $options
     * @return array<string,mixed>
     */
    private function line(Product $product, int $quantity = 1, array $options = []): array
    {
        return ['productId' => (string) $product->id, 'quantity' => $quantity, 'varientId' => null, 'options' => $options];
    }

    /**
     * @return array<string,mixed>
     */
    private function choose(OptionGroup $group, OptionValue ...$values): array
    {
        return [
            'optionId' => (string) $group->id,
            'values' => array_map(fn (OptionValue $value) => ['valueId' => (string) $value->id, 'quantity' => 1], $values),
        ];
    }

    private function areaWithFee(?int $fils, bool $active = true): DeliveryArea
    {
        return DeliveryArea::factory()->for(DeliveryCity::factory(), 'city')->create(['fee_fils' => $fils, 'is_active' => $active]);
    }

    // ------------------------------------------------------------------ lines

    public function test_a_plain_line_costs_the_price_times_the_quantity_and_delivery_is_not_worked_out_without_an_area(): void
    {
        $product = Product::factory()->priced(40_000)->create();

        $cart = $this->price(['a' => $this->line($product, 2)]);

        $this->assertSame(80_000, $cart->subTotalFils);
        $this->assertSame(80_000, $cart->totalFils());
        $this->assertSame(2, $cart->totalQuantity());
        $this->assertFalse($cart->deliveryResolved);
        $this->assertSame(0, $cart->deliveryFeeFils);
        $this->assertTrue($cart->canPlaceOrder());
    }

    public function test_a_products_own_discount_is_already_in_the_line_price_and_the_list_price_is_kept_for_comparison(): void
    {
        $product = Product::factory()->priced(35_000)->fixedDiscount(16_000)->create();

        $cart = $this->price(['a' => $this->line($product, 2)]);
        $line = $cart->lines[0];

        $this->assertSame([35_000, 19_000], [$line->listUnitPriceFils, $line->unitPriceFils]);
        $this->assertSame(38_000, $cart->subTotalFils);
        $this->assertSame(0, $cart->discountFils);
    }

    public function test_a_product_priced_by_its_options_costs_the_chosen_option(): void
    {
        $jar = Product::factory()->priced(0)->create();
        $group = OptionGroup::factory()->for($jar)->create();
        $small = OptionValue::factory()->for($group, 'group')->priced(60_000)->create();
        OptionValue::factory()->for($group, 'group')->priced(145_500)->create();

        $cart = $this->price(['a' => $this->line($jar, 1, [$this->choose($group, $small)])]);

        $this->assertSame(60_000, $cart->subTotalFils);
        $this->assertTrue($cart->canPlaceOrder());
    }

    public function test_a_package_adds_every_chosen_value_to_the_discounted_base_price(): void
    {
        $package = Product::factory()->priced(20_000)->percentDiscount(12.5)->create();
        $group = OptionGroup::factory()->for($package)->checkbox(3, 3)->create();
        [$a, $b, $c] = OptionValue::factory()->for($group, 'group')->priced(5_000)->count(3)->create()->all();

        $cart = $this->price(['a' => $this->line($package, 1, [$this->choose($group, $a, $b, $c)])]);
        $line = $cart->lines[0];

        // 20.000 - 12.5% = 17.500, plus 3 x 5.000.
        $this->assertSame(32_500, $line->unitPriceFils);
        $this->assertSame(35_000, $line->listUnitPriceFils);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidChoices(): array
    {
        return [
            'a required group left out' => ['missing-required'],
            'two values in a single-choice group' => ['two-in-radio'],
            'a value that is not in the group' => ['foreign-value'],
            'an option group the product does not have' => ['unknown-group'],
            'a value that has been switched off' => ['inactive-value'],
            'an empty choice' => ['empty'],
        ];
    }

    #[DataProvider('invalidChoices')]
    public function test_a_line_with_invalid_option_choices_cannot_be_ordered(string $case): void
    {
        $product = Product::factory()->priced(0)->create();
        $group = OptionGroup::factory()->for($product)->create();
        $first = OptionValue::factory()->for($group, 'group')->priced(10_000)->create();
        $second = OptionValue::factory()->for($group, 'group')->priced(20_000)->create();
        $off = OptionValue::factory()->for($group, 'group')->create(['is_active' => false]);
        $otherValue = OptionValue::factory()->create();

        $options = match ($case) {
            'missing-required' => [],
            'two-in-radio' => [$this->choose($group, $first, $second)],
            'foreign-value' => [['optionId' => (string) $group->id, 'values' => [['valueId' => (string) $otherValue->id, 'quantity' => 1]]]],
            'unknown-group' => [['optionId' => '999999', 'values' => [['valueId' => (string) $first->id, 'quantity' => 1]]]],
            'inactive-value' => [$this->choose($group, $off)],
            'empty' => [['optionId' => (string) $group->id, 'values' => []]],
        };

        $cart = $this->price(['a' => $this->line($product, 1, $options)]);

        $this->assertFalse($cart->lines[0]->available);
        $this->assertSame('Please choose from the available options', $cart->lines[0]->message);
        $this->assertSame(0, $cart->subTotalFils);
        $this->assertFalse($cart->canPlaceOrder());
    }

    public function test_an_optional_group_may_be_left_out(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        OptionGroup::factory()->for($product)->optional()->has(OptionValue::factory()->count(2), 'values')->create();

        $cart = $this->price(['a' => $this->line($product)]);

        $this->assertTrue($cart->lines[0]->available);
        $this->assertSame(10_000, $cart->subTotalFils);
    }

    public function test_a_package_needs_exactly_its_number_of_picks(): void
    {
        $package = Product::factory()->priced(20_000)->create();
        $group = OptionGroup::factory()->for($package)->checkbox(3, 3)->create();
        $values = OptionValue::factory()->for($group, 'group')->count(4)->create()->all();

        $tooFew = $this->price(['a' => $this->line($package, 1, [$this->choose($group, ...array_slice($values, 0, 2))])]);
        $tooMany = $this->price(['a' => $this->line($package, 1, [$this->choose($group, ...$values)])]);
        $exact = $this->price(['a' => $this->line($package, 1, [$this->choose($group, ...array_slice($values, 0, 3))])]);

        $this->assertFalse($tooFew->lines[0]->available);
        $this->assertFalse($tooMany->lines[0]->available);
        $this->assertTrue($exact->lines[0]->available);
    }

    public function test_a_basket_started_on_overzaki_still_prices_by_the_ids_products_and_options_had_there(): void
    {
        $product = Product::factory()->priced(0)->create(['overzaki_id' => 'ovz-product']);
        $group = OptionGroup::factory()->for($product)->create(['overzaki_id' => 'ovz-group']);
        OptionValue::factory()->for($group, 'group')->priced(60_000)->create(['overzaki_id' => 'ovz-value']);

        $cart = $this->price(['a' => [
            'productId' => 'ovz-product',
            'quantity' => 1,
            'varientId' => null,
            'options' => [['optionId' => 'ovz-group', 'values' => [['valueId' => 'ovz-value', 'quantity' => 1]]]],
        ]]);

        $this->assertTrue($cart->lines[0]->available);
        $this->assertSame(60_000, $cart->subTotalFils);
    }

    public function test_a_product_that_is_gone_or_switched_off_cannot_be_ordered_and_adds_nothing(): void
    {
        $live = Product::factory()->priced(10_000)->create();
        $off = Product::factory()->priced(50_000)->inactive()->create();

        $cart = $this->price([
            'live' => $this->line($live),
            'off' => $this->line($off),
            'ghost' => ['productId' => '999999', 'quantity' => 1, 'varientId' => null, 'options' => []],
        ]);

        $this->assertSame(10_000, $cart->subTotalFils);
        $this->assertSame([true, false, false], array_map(fn ($line) => $line->available, $cart->lines));
        $this->assertSame('This product is no longer available', $cart->lines[1]->message);
        $this->assertFalse($cart->canPlaceOrder());
    }

    public function test_stock_limits_are_enforced_per_line(): void
    {
        $scarce = Product::factory()->withStock(3)->create();
        $gone = Product::factory()->withStock(0)->create();
        $capped = Product::factory()->create(['max_per_order' => 2]);

        $cart = $this->price([
            'ok' => $this->line($scarce, 3),
            'over' => $this->line($scarce, 4),
            'none' => $this->line($gone),
            'cap' => $this->line($capped, 3),
        ]);

        $this->assertSame([true, false, false, false], array_map(fn ($line) => $line->available, $cart->lines));
        $this->assertSame('Only 3 left in stock', $cart->lines[1]->message);
        $this->assertSame('Out of stock', $cart->lines[2]->message);
        $this->assertSame('You can order up to 2 of this product', $cart->lines[3]->message);
    }

    public function test_a_quantity_tier_takes_its_discount_off_the_line_once_at_the_highest_tier_reached(): void
    {
        // The figures are what Overzaki's checker answers for the shop's real
        // "buy 2 save 5, buy 3 save 10" product at KWD 10.
        $product = Product::factory()->priced(10_000)->create();
        ProductQuantityTier::factory()->for($product)->from(1)->create();
        ProductQuantityTier::factory()->for($product)->from(2)->fixed(5_000)->create();
        ProductQuantityTier::factory()->for($product)->from(3)->fixed(10_000)->create();

        $totals = array_map(
            fn (int $quantity) => $this->price(['a' => $this->line($product, $quantity)])->subTotalFils,
            [1, 2, 3, 4, 6, 12]
        );

        $this->assertSame([10_000, 15_000, 20_000, 30_000, 50_000, 110_000], $totals);
    }

    public function test_a_percentage_tier_is_a_share_of_the_line_after_the_products_own_discount_and_options(): void
    {
        $product = Product::factory()->priced(12_000)->fixedDiscount(2_000)->create();
        ProductQuantityTier::factory()->for($product)->from(4)->percentage(10)->create();

        $below = $this->price(['a' => $this->line($product, 3)]);
        $at = $this->price(['a' => $this->line($product, 4)]);

        $this->assertSame(30_000, $below->subTotalFils);
        // 4 x 10.000 = 40.000, less 10% = 4.000.
        $this->assertSame([40_000 - 4_000, 4_000], [$at->subTotalFils, $at->lines[0]->quantityDiscountFils]);
    }

    public function test_a_tier_can_never_take_more_than_the_line_costs(): void
    {
        $product = Product::factory()->priced(1_000)->create();
        ProductQuantityTier::factory()->for($product)->from(2)->fixed(50_000)->create();

        $cart = $this->price(['a' => $this->line($product, 2)]);

        $this->assertSame(0, $cart->subTotalFils);
    }

    public function test_a_tier_with_no_discount_changes_nothing(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        ProductQuantityTier::factory()->for($product)->from(1)->create();

        $cart = $this->price(['a' => $this->line($product, 3)]);

        $this->assertSame(30_000, $cart->subTotalFils);
    }

    public function test_a_tier_that_waives_delivery_does_so_only_once_it_is_reached(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        ProductQuantityTier::factory()->for($product)->from(3)->withFreeDelivery()->create();
        $area = $this->areaWithFee(2_000);
        $context = new PricingContext(areaId: $area->id);

        $below = $this->price(['a' => $this->line($product, 2)], $context);
        $at = $this->price(['a' => $this->line($product, 3)], $context);

        $this->assertSame([2_000, 0], [$below->deliveryFeeFils, $below->shippingDiscountFils]);
        $this->assertSame([0, 2_000], [$at->deliveryFeeFils, $at->shippingDiscountFils]);
    }

    public function test_a_line_that_cannot_be_sold_earns_no_tier_discount(): void
    {
        $product = Product::factory()->priced(10_000)->withStock(1)->create();
        ProductQuantityTier::factory()->for($product)->from(2)->fixed(5_000)->create();

        $cart = $this->price(['a' => $this->line($product, 2)]);

        $this->assertFalse($cart->lines[0]->available);
        $this->assertSame(0, $cart->lines[0]->quantityDiscountFils);
    }

    public function test_an_empty_basket_cannot_be_ordered(): void
    {
        $cart = $this->price([]);

        $this->assertTrue($cart->isEmpty());
        $this->assertSame(0, $cart->totalFils());
        $this->assertFalse($cart->canPlaceOrder());
    }

    // --------------------------------------------------------------- delivery

    public function test_delivery_uses_the_areas_own_fee_or_the_default_when_it_has_none(): void
    {
        config(['store.commerce.delivery_fee_fils' => 2_500]);
        $product = Product::factory()->priced(10_000)->create();
        $ownFee = $this->areaWithFee(1_500);
        $noFee = $this->areaWithFee(null);

        $own = $this->price(['a' => $this->line($product)], new PricingContext(areaId: $ownFee->id));
        $default = $this->price(['a' => $this->line($product)], new PricingContext(areaId: $noFee->id));

        $this->assertSame([1_500, 11_500, true], [$own->deliveryFeeFils, $own->totalFils(), $own->deliveryResolved]);
        $this->assertSame([2_500, 12_500], [$default->deliveryFeeFils, $default->totalFils()]);
    }

    public function test_an_area_that_is_switched_off_or_unknown_blocks_the_order(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        $closed = $this->areaWithFee(2_000, active: false);
        $inClosedCity = DeliveryArea::factory()->for(DeliveryCity::factory()->create(['is_active' => false]), 'city')->create();

        foreach ([$closed->id, $inClosedCity->id, 999_999] as $areaId) {
            $cart = $this->price(['a' => $this->line($product)], new PricingContext(areaId: $areaId));

            $this->assertFalse($cart->canPlaceOrder(), "area {$areaId}");
            $this->assertSame(['We do not deliver to this area yet'], $cart->problems);
        }
    }

    public function test_delivery_is_free_once_the_subtotal_reaches_the_threshold(): void
    {
        $this->setting(['commerce.free_shipping_fils' => 30_000]);
        $product = Product::factory()->priced(10_000)->create();
        $area = $this->areaWithFee(2_000);
        $context = new PricingContext(areaId: $area->id);

        $below = $this->price(['a' => $this->line($product, 2)], $context);
        $at = $this->price(['a' => $this->line($product, 3)], $context);

        $this->assertSame([2_000, 0, 22_000], [$below->deliveryFeeFils, $below->shippingDiscountFils, $below->totalFils()]);
        $this->assertSame([0, 2_000, 30_000], [$at->deliveryFeeFils, $at->shippingDiscountFils, $at->totalFils()]);
    }

    public function test_no_threshold_means_delivery_is_never_waived_by_size(): void
    {
        $product = Product::factory()->priced(1_000_000)->create();
        $area = $this->areaWithFee(2_000);

        $cart = $this->price(['a' => $this->line($product)], new PricingContext(areaId: $area->id));

        $this->assertSame(2_000, $cart->deliveryFeeFils);
    }

    // --------------------------------------------------------------- vouchers

    public function test_a_fixed_voucher_takes_its_amount_off_but_never_more_than_the_subtotal(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        Voucher::factory()->fixed(3_000)->create(['code' => 'THREE']);
        Voucher::factory()->fixed(50_000)->create(['code' => 'HUGE']);

        $three = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: 'THREE'));
        $huge = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: 'HUGE'));

        $this->assertSame([3_000, 7_000], [$three->discountFils, $three->totalFils()]);
        $this->assertSame([10_000, 0], [$huge->discountFils, $huge->totalFils()]);
    }

    public function test_a_percentage_voucher_rounds_half_a_fils_up_and_respects_its_cap(): void
    {
        $product = Product::factory()->priced(10_005)->create();
        Voucher::factory()->percentage(10)->create(['code' => 'TEN']);
        Voucher::factory()->percentage(10, maxDiscountFils: 500)->create(['code' => 'CAPPED']);

        $ten = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: 'TEN'));
        $capped = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: 'CAPPED'));

        // 10% of 10,005 fils is 1,000.5, which rounds up to 1,001.
        $this->assertSame([1_001, 9_004], [$ten->discountFils, $ten->totalFils()]);
        $this->assertSame([500, 9_505], [$capped->discountFils, $capped->totalFils()]);
    }

    public function test_a_free_shipping_voucher_waives_the_delivery_fee(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        $area = $this->areaWithFee(2_000);
        Voucher::factory()->freeShipping()->create(['code' => 'SHIPFREE']);

        $cart = $this->price(['a' => $this->line($product)], new PricingContext(areaId: $area->id, voucherCode: 'SHIPFREE'));

        $this->assertSame([0, 2_000, 0, 10_000], [$cart->deliveryFeeFils, $cart->shippingDiscountFils, $cart->discountFils, $cart->totalFils()]);
    }

    public function test_a_voucher_code_matches_without_regard_to_case_or_stray_spaces(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        Voucher::factory()->fixed(1_000)->create(['code' => ' welcome ']);

        $cart = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: '  Welcome'));

        $this->assertTrue($cart->voucher->accepted);
        $this->assertSame(1_000, $cart->discountFils);
        $this->assertSame('WELCOME', $cart->voucher->voucher->code);
    }

    /**
     * @return array<string, array{string,string}>
     */
    public static function rejectedVouchers(): array
    {
        return [
            'unknown' => ['NOPE', 'This code is not valid'],
            'switched off' => ['OFF', 'This code is not valid'],
            'expired' => ['OLD', 'This code has expired'],
            'not started' => ['SOON', 'This code is not active yet'],
            'below its minimum' => ['BIG', 'This code needs an order of at least 50 KWD'],
        ];
    }

    #[DataProvider('rejectedVouchers')]
    public function test_a_voucher_that_does_not_apply_is_refused_with_the_reason_and_changes_nothing(string $code, string $message): void
    {
        $this->travelTo('2026-10-05 12:00:00');
        $product = Product::factory()->priced(10_000)->create();
        Voucher::factory()->inactive()->create(['code' => 'OFF']);
        Voucher::factory()->between(null, '2026-10-01 00:00:00')->create(['code' => 'OLD']);
        Voucher::factory()->between('2026-11-01 00:00:00', null)->create(['code' => 'SOON']);
        Voucher::factory()->minimumSubtotal(50_000)->create(['code' => 'BIG']);

        $cart = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: $code));

        $this->assertFalse($cart->voucher->accepted);
        $this->assertSame($message, $cart->voucher->message);
        $this->assertSame(0, $cart->discountFils);
        $this->assertSame(10_000, $cart->totalFils());
        $this->assertFalse($cart->canPlaceOrder());
    }

    public function test_a_voucher_stops_working_once_its_total_usage_limit_is_reached_by_orders_that_were_not_cancelled(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        Voucher::factory()->fixed(1_000)->limitedTo(2)->create(['code' => 'TWICE']);
        Order::factory()->usingVoucher('TWICE')->create();
        Order::factory()->usingVoucher('TWICE')->withStatus(OrderStatus::Cancelled)->create();

        $withOneLeft = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: 'TWICE'));
        Order::factory()->usingVoucher('TWICE')->create();
        $used = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: 'TWICE'));

        $this->assertTrue($withOneLeft->voucher->accepted);
        $this->assertFalse($used->voucher->accepted);
        $this->assertSame('This code has reached its usage limit', $used->voucher->message);
    }

    public function test_a_per_customer_limit_counts_by_account_or_by_phone_number(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        Voucher::factory()->fixed(1_000)->limitedTo(null, 1)->create(['code' => 'ONCE']);
        $customer = Customer::factory()->create();
        Order::factory()->forCustomer($customer)->usingVoucher('ONCE')->create();
        Order::factory()->usingVoucher('ONCE')->create(['customer_phone' => '+96555500000']);

        $byAccount = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: 'ONCE', customerId: $customer->id));
        $byPhone = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: 'ONCE', phone: '+96555500000'));
        $stranger = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: 'ONCE', phone: '+96599900000'));
        $unknown = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: 'ONCE'));

        $this->assertSame('You have already used this code', $byAccount->voucher->message);
        $this->assertSame('You have already used this code', $byPhone->voucher->message);
        $this->assertTrue($stranger->voucher->accepted);
        $this->assertTrue($unknown->voucher->accepted);
    }

    public function test_no_code_entered_is_not_an_error(): void
    {
        $product = Product::factory()->priced(10_000)->create();

        $cart = $this->price(['a' => $this->line($product)], new PricingContext(voucherCode: '   '));

        $this->assertTrue($cart->voucher->accepted);
        $this->assertNull($cart->voucher->message);
        $this->assertTrue($cart->canPlaceOrder());
    }

    // ----------------------------------------------- add-ons, COD and minimum

    public function test_chosen_add_ons_are_added_and_an_unavailable_one_blocks_the_order(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        $wrap = ServiceAddon::factory()->create(['price_fils' => 1_500]);
        $ribbon = ServiceAddon::factory()->create(['price_fils' => 500]);
        $retired = ServiceAddon::factory()->create(['is_active' => false]);

        $both = $this->price(['a' => $this->line($product)], new PricingContext(addonIds: [$wrap->id, $ribbon->id]));
        $withRetired = $this->price(['a' => $this->line($product)], new PricingContext(addonIds: [$wrap->id, $retired->id]));

        $this->assertSame([2_000, 12_000, true], [$both->addonsTotalFils, $both->totalFils(), $both->canPlaceOrder()]);
        $this->assertFalse($withRetired->canPlaceOrder());
        $this->assertSame(['One of the selected extras is no longer available'], $withRetired->problems);
    }

    public function test_the_cash_on_delivery_fee_is_charged_only_when_paying_that_way(): void
    {
        $this->setting(['commerce.cod_fee_fils' => 750]);
        $product = Product::factory()->priced(10_000)->create();

        $cod = $this->price(['a' => $this->line($product)], new PricingContext(cashOnDelivery: true));
        $online = $this->price(['a' => $this->line($product)], new PricingContext(cashOnDelivery: false));

        $this->assertSame([750, 10_750], [$cod->codFeeFils, $cod->totalFils()]);
        $this->assertSame([0, 10_000], [$online->codFeeFils, $online->totalFils()]);
    }

    public function test_cash_on_delivery_needs_the_shop_switch_on_and_every_product_to_allow_it(): void
    {
        $allowed = Product::factory()->create();
        $refused = Product::factory()->create(['cod_enabled' => false]);

        $mixed = $this->price(['a' => $this->line($allowed), 'b' => $this->line($refused)]);
        $onlyAllowed = $this->price(['a' => $this->line($allowed)]);
        $this->setting(['checkout.cod' => false]);
        $switchedOff = $this->price(['a' => $this->line($allowed)]);

        $this->assertFalse($mixed->cashOnDeliveryAvailable);
        $this->assertTrue($onlyAllowed->cashOnDeliveryAvailable);
        $this->assertFalse($switchedOff->cashOnDeliveryAvailable);
    }

    public function test_an_order_below_the_minimum_cannot_be_placed_and_one_at_the_minimum_can(): void
    {
        $this->setting(['commerce.minimum_order_fils' => 20_000]);
        $product = Product::factory()->priced(10_000)->create();

        $below = $this->price(['a' => $this->line($product, 1)]);
        $at = $this->price(['a' => $this->line($product, 2)]);

        $this->assertFalse($below->canPlaceOrder());
        $this->assertSame(['Minimum order is 20 KWD'], $below->problems);
        $this->assertTrue($at->canPlaceOrder());
    }

    // ----------------------------------------------------------- the whole sum

    public function test_the_total_is_subtotal_minus_voucher_plus_delivery_add_ons_and_the_cod_fee(): void
    {
        $this->setting(['commerce.cod_fee_fils' => 500]);
        $discounted = Product::factory()->priced(35_000)->fixedDiscount(16_000)->create();
        $plain = Product::factory()->priced(12_500)->create();
        $area = $this->areaWithFee(2_000);
        $wrap = ServiceAddon::factory()->create(['price_fils' => 1_500]);
        Voucher::factory()->percentage(10)->create(['code' => 'TEN']);

        $cart = $this->price(
            ['a' => $this->line($discounted, 2), 'b' => $this->line($plain, 1)],
            new PricingContext(areaId: $area->id, voucherCode: 'TEN', addonIds: [$wrap->id], cashOnDelivery: true)
        );

        // 2 x 19.000 + 12.500 = 50.500; 10% off = 5.050; + 2.000 + 1.500 + 0.500.
        $this->assertSame(50_500, $cart->subTotalFils);
        $this->assertSame(5_050, $cart->discountFils);
        $this->assertSame(49_450, $cart->totalFils());
    }

    // -------------------------------------------------------- the quote shape

    public function test_the_quote_data_is_in_dinars_in_the_shape_the_cart_views_read(): void
    {
        $product = Product::factory()->priced(35_000)->fixedDiscount(16_000)->create();
        $area = $this->areaWithFee(2_000);
        $ghost = ['productId' => '999999', 'quantity' => 1, 'varientId' => null, 'options' => []];

        $data = $this->price(['a' => $this->line($product, 2), 'b' => $ghost], new PricingContext(areaId: $area->id))->toQuoteData();

        $this->assertSame(38, $data['subTotal']);
        $this->assertSame(2, $data['deliveryFees']);
        $this->assertSame(40, $data['total']);
        $this->assertSame(40, $data['amountToPay']);
        $this->assertSame(0, $data['vat']);
        $this->assertSame($area->id, $data['location']['area']);
        $this->assertFalse($data['validToCreateOrder']);
        $this->assertSame(['ar' => 'د.ك', 'en' => 'KWD'], $data['symbol']);
        $this->assertSame(['a', 'b'], array_column($data['items'], '_id'));
        $this->assertSame([35, 19, 70, 38, true, 2], [
            $data['items'][0]['unitPrice'], $data['items'][0]['unitPriceAfterDiscount'],
            $data['items'][0]['totalPrice'], $data['items'][0]['totalPriceAfterDiscount'],
            $data['items'][0]['status'], $data['items'][0]['quantity'],
        ]);
        $this->assertFalse($data['items'][1]['status']);
        $this->assertSame('This product is no longer available', $data['items'][1]['msg']);
    }
}
