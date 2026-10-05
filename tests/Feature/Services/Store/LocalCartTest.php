<?php

namespace Tests\Feature\Services\Store;

use App\Models\DeliveryArea;
use App\Models\DeliveryCity;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\Voucher;
use App\Services\Store\LocalCart;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\Concerns\IsolatesSettings;
use Tests\TestCase;

class LocalCartTest extends TestCase
{
    use IsolatesSettings, LazilyRefreshDatabase;

    private function cart(): LocalCart
    {
        return $this->app->make(LocalCart::class);
    }

    public function test_adding_the_same_product_with_the_same_options_adds_to_the_line(): void
    {
        $product = Product::factory()->create();

        $this->cart()->add((string) $product->id, 2);
        $this->cart()->add((string) $product->id, 3);

        $this->assertCount(1, $this->cart()->items());
        $this->assertSame(5, $this->cart()->count());
        $this->assertTrue($this->cart()->has((string) $product->id));
    }

    public function test_the_same_product_with_different_options_is_a_separate_line(): void
    {
        $product = Product::factory()->priced(0)->create();
        $group = OptionGroup::factory()->for($product)->create();
        $small = OptionValue::factory()->for($group, 'group')->priced(10_000)->create();
        $large = OptionValue::factory()->for($group, 'group')->priced(20_000)->create();
        $pick = fn (OptionValue $value) => [['optionId' => (string) $group->id, 'values' => [['valueId' => (string) $value->id, 'quantity' => 1]]]];

        $this->cart()->add((string) $product->id, 1, null, $pick($small));
        $this->cart()->add((string) $product->id, 1, null, $pick($large));

        $this->assertCount(2, $this->cart()->items());
        $this->assertSame(30.0, $this->cart()->quote()->subTotal());
    }

    public function test_changing_a_quantity_removing_a_line_and_clearing_the_basket(): void
    {
        $first = Product::factory()->create();
        $second = Product::factory()->create();
        $this->cart()->add((string) $first->id);
        $this->cart()->add((string) $second->id);
        [$firstKey, $secondKey] = array_keys($this->cart()->items());

        $changed = $this->cart()->updateQuantity($firstKey, 4);
        $removed = $this->cart()->updateQuantity($secondKey, 0);
        $missing = $this->cart()->remove('not-a-line');

        $this->assertTrue($changed);
        $this->assertTrue($removed);
        $this->assertFalse($missing);
        $this->assertSame(4, $this->cart()->count());

        $this->cart()->clear();

        $this->assertTrue($this->cart()->isEmpty());
    }

    public function test_the_quote_prices_each_line_and_maps_lines_back_to_their_basket_keys(): void
    {
        $discounted = Product::factory()->priced(35_000)->fixedDiscount(16_000)->create(['name_en' => 'Albustan']);
        $plain = Product::factory()->priced(12_500)->create(['name_en' => 'Plain']);
        $this->cart()->add((string) $discounted->id, 2);
        $this->cart()->add((string) $plain->id, 1);

        $quote = $this->cart()->quote();
        $lines = $quote->lines();

        $this->assertSame(50.5, $quote->subTotal());
        $this->assertSame(50.5, $quote->total());
        $this->assertSame(3, $quote->totalQuantity());
        $this->assertTrue($quote->canPlaceOrder());
        $this->assertSame(array_keys($this->cart()->items()), array_column($lines, 'key'));
        $this->assertSame([38.0, 12.5], array_column($lines, 'total'));
        $this->assertSame([35.0, 12.5], array_column($lines, 'listPrice'));
        $this->assertSame(['Albustan', 'Plain'], array_map(fn ($line) => $line['product']->name(), $lines));
    }

    public function test_an_empty_basket_quotes_as_empty(): void
    {
        $quote = $this->cart()->quote();

        $this->assertTrue($quote->isEmpty());
        $this->assertFalse($quote->canPlaceOrder());
    }

    public function test_a_product_that_has_gone_shows_as_unavailable_and_blocks_the_order(): void
    {
        $this->cart()->add('999999', 1);

        $quote = $this->cart()->quote();

        $this->assertFalse($quote->lines()[0]['available']);
        $this->assertSame('This product is no longer available', $quote->lines()[0]['message']);
        $this->assertFalse($quote->canPlaceOrder());
        $this->assertSame(['This product is no longer available'], $quote->problems());
    }

    public function test_a_voucher_held_in_the_session_applies_to_every_quote_until_another_is_named(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        Voucher::factory()->fixed(1_000)->create(['code' => 'SAVE1']);
        Voucher::factory()->fixed(3_000)->create(['code' => 'SAVE3']);
        $this->cart()->add((string) $product->id);
        Session::put('cart.voucher', 'SAVE1');

        $fromSession = $this->cart()->quote();
        $named = $this->cart()->quote(['voucher' => 'SAVE3']);
        $cleared = $this->cart()->quote(['voucher' => null]);

        $this->assertSame([1.0, 9.0, 'SAVE1'], [$fromSession->discount(), $fromSession->total(), $fromSession->voucherCode()]);
        $this->assertSame([3.0, 7.0], [$named->discount(), $named->total()]);
        $this->assertSame([0.0, 10.0], [$cleared->discount(), $cleared->total()]);
    }

    public function test_a_voucher_that_does_not_apply_is_reported_for_the_form_to_show(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        $this->cart()->add((string) $product->id);

        $quote = $this->cart()->quote(['voucher' => 'NOPE']);

        $this->assertFalse($quote->voucherAccepted());
        $this->assertSame('This code is not valid', $quote->voucherMessage());
    }

    public function test_delivery_is_worked_out_once_an_area_is_given(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        $area = DeliveryArea::factory()->for(DeliveryCity::factory(), 'city')->create(['fee_fils' => 2_000]);
        $this->cart()->add((string) $product->id);

        $withoutArea = $this->cart()->quote();
        $withArea = $this->cart()->quote(['area' => $area->id]);

        $this->assertFalse($withoutArea->deliveryResolved());
        $this->assertTrue($withArea->deliveryResolved());
        $this->assertSame([2.0, 12.0], [$withArea->deliveryFees(), $withArea->total()]);
    }

    public function test_cash_on_delivery_is_offered_unless_the_shop_or_a_product_refuses_it(): void
    {
        $allowed = Product::factory()->create();
        $refused = Product::factory()->create(['cod_enabled' => false]);
        $this->cart()->add((string) $allowed->id);

        $this->assertTrue($this->cart()->quote()->supportsCashOnDelivery());

        $this->cart()->add((string) $refused->id);

        $this->assertFalse($this->cart()->quote()->supportsCashOnDelivery());
    }
}
