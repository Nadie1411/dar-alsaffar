<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Contracts\Store\Orders;
use App\Models\DeliveryArea;
use App\Models\DeliveryCity;
use App\Models\Order;
use App\Models\Product;
use App\Services\Store\Pricing\CommerceSettings;
use App\Services\Store\Pricing\PricingContext;
use App\Services\Store\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class DeliveryManagementTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs('manager');
    }

    // -------------------------------------------------------------- settings

    public function test_the_page_shows_the_defaults_from_config_until_the_shop_sets_its_own(): void
    {
        $this->get(route('panel.delivery.index'))
            ->assertOk()
            ->assertSee('value="2.000"', false);
    }

    public function test_the_settings_are_saved_in_fils_and_read_back_by_checkout(): void
    {
        $this->put(route('panel.delivery.settings'), [
            'delivery_fee' => '١٫٥٠٠', 'free_shipping' => '25', 'minimum_order' => '7.500',
        ])->assertSessionHasNoErrors();

        $commerce = app(CommerceSettings::class);

        $this->assertSame(1_500, $commerce->deliveryFeeFils());
        $this->assertSame(25_000, $commerce->freeShippingThresholdFils());
        $this->assertSame(7_500, $commerce->minimumOrderFils());
        $this->assertDatabaseHas('activity_logs', ['action' => 'delivery.settings_updated']);

        $this->get(route('panel.delivery.index'))->assertSee('value="1.500"', false)->assertSee('value="25.000"', false);
    }

    public function test_leaving_free_delivery_and_the_minimum_empty_switches_them_off(): void
    {
        $this->put(route('panel.delivery.settings'), ['delivery_fee' => '2', 'free_shipping' => '30', 'minimum_order' => '10']);
        $this->put(route('panel.delivery.settings'), ['delivery_fee' => '2', 'free_shipping' => '', 'minimum_order' => '']);

        $commerce = app(CommerceSettings::class);

        $this->assertSame(0, $commerce->freeShippingThresholdFils());
        $this->assertSame(0, $commerce->minimumOrderFils());
    }

    public function test_a_default_fee_is_required_and_every_amount_must_be_money(): void
    {
        $this->put(route('panel.delivery.settings'), ['delivery_fee' => '', 'free_shipping' => '', 'minimum_order' => ''])
            ->assertSessionHasErrors('delivery_fee');
        $this->put(route('panel.delivery.settings'), ['delivery_fee' => 'two', 'free_shipping' => 'many', 'minimum_order' => '-1'])
            ->assertSessionHasErrors(['delivery_fee', 'free_shipping', 'minimum_order']);
    }

    public function test_the_pricing_engine_charges_what_the_shop_set_here(): void
    {
        $area = DeliveryArea::factory()->create(['fee_fils' => null]);
        $special = DeliveryArea::factory()->create(['fee_fils' => 3_500]);
        $product = Product::factory()->priced(10_000)->create();
        $basket = ['line' => ['productId' => (string) $product->id, 'quantity' => 1, 'options' => []]];

        $this->put(route('panel.delivery.settings'), ['delivery_fee' => '1.250', 'free_shipping' => '15', 'minimum_order' => '8']);

        $engine = app(PricingEngine::class);

        $this->assertSame(1_250, $engine->price($basket, new PricingContext(areaId: $area->id))->deliveryFeeFils, 'the default fee');
        $this->assertSame(3_500, $engine->price($basket, new PricingContext(areaId: $special->id))->deliveryFeeFils, 'an area with its own fee');

        $big = ['line' => ['productId' => (string) $product->id, 'quantity' => 2, 'options' => []]];
        $this->assertSame(0, $engine->price($big, new PricingContext(areaId: $area->id))->deliveryFeeFils, 'free from 15 KWD');
        $this->assertSame(8_000, $engine->price($basket, new PricingContext(areaId: $area->id))->minimumOrderFils);
    }

    // ---------------------------------------------------------------- cities

    public function test_the_list_shows_each_city_with_how_many_of_its_areas_are_on(): void
    {
        $city = DeliveryCity::factory()->create(['name_en' => 'Hawalli']);
        DeliveryArea::factory()->count(2)->for($city, 'city')->create();
        DeliveryArea::factory()->for($city, 'city')->create(['is_active' => false]);

        $row = $this->get(route('panel.delivery.index'))->assertSee('Hawalli')->assertSee('2 / 3')->viewData('cities')->first();

        $this->assertSame(3, $row->areas_count);
        $this->assertSame(2, $row->active_areas_count);
    }

    public function test_a_city_is_added_and_its_page_opens_ready_for_areas(): void
    {
        $this->post(route('panel.delivery.cities.store'), ['name_ar' => 'حولي', 'name_en' => 'Hawalli'])
            ->assertRedirect();

        $city = DeliveryCity::query()->firstOrFail();

        $this->assertTrue($city->is_active);
        $this->get(route('panel.delivery.cities.edit', $city))->assertOk()->assertSee('Hawalli');
        $this->assertDatabaseHas('activity_logs', ['action' => 'delivery.city_created', 'subject_label' => 'حولي']);
    }

    public function test_a_new_city_goes_to_the_end_of_the_list(): void
    {
        DeliveryCity::factory()->create(['sort_order' => 4]);

        $this->post(route('panel.delivery.cities.store'), ['name_ar' => 'ب', 'name_en' => 'B']);

        $this->assertSame(5, DeliveryCity::query()->where('name_en', 'B')->firstOrFail()->sort_order);
    }

    public function test_a_city_needs_both_names(): void
    {
        $this->post(route('panel.delivery.cities.store'), ['name_ar' => '', 'name_en' => ''])->assertSessionHasErrors(['name_ar', 'name_en']);
    }

    public function test_the_city_page_lists_its_areas_with_the_fee_blank_where_the_default_applies(): void
    {
        $city = DeliveryCity::factory()->create();
        DeliveryArea::factory()->for($city, 'city')->create(['name_en' => 'Salmiya', 'fee_fils' => null]);
        DeliveryArea::factory()->for($city, 'city')->create(['name_en' => 'Rumaithiya', 'fee_fils' => 3_000]);

        $this->get(route('panel.delivery.cities.edit', $city))
            ->assertSee('value="Salmiya"', false)
            ->assertSee('value="3.000"', false)
            ->assertSee('placeholder="2.000"', false);
    }

    public function test_a_city_and_its_areas_are_saved_together(): void
    {
        $city = DeliveryCity::factory()->create(['name_en' => 'Old City']);
        $rename = DeliveryArea::factory()->for($city, 'city')->create(['name_en' => 'Before', 'fee_fils' => null]);
        $remove = DeliveryArea::factory()->for($city, 'city')->create(['name_en' => 'Remove me']);
        $off = DeliveryArea::factory()->for($city, 'city')->create(['name_en' => 'Switch off']);

        $this->put(route('panel.delivery.cities.update', $city), [
            'name_ar' => 'مدينة جديدة', 'name_en' => 'New City',
            'areas' => [
                $rename->id => ['name_ar' => 'بعد', 'name_en' => 'After', 'fee' => '1.750', 'is_active' => '1'],
                $remove->id => ['name_ar' => 'ا', 'name_en' => 'Remove me', 'fee' => '', 'is_active' => '1', 'remove' => '1'],
                $off->id => ['name_ar' => 'ا', 'name_en' => 'Switch off', 'fee' => ''],
            ],
            'new_areas' => [['name_ar' => 'جديدة', 'name_en' => 'Fresh', 'fee' => '٢']],
        ])->assertSessionHasNoErrors();

        $city->refresh();
        $this->assertSame('New City', $city->name_en);
        $this->assertFalse($city->is_active, 'the switch was left off');
        $this->assertSame(['After', 'Switch off', 'Fresh'], $city->areas()->orderBy('id')->pluck('name_en')->all());
        $this->assertSame(1_750, $rename->fresh()->fee_fils);
        $this->assertNull($off->fresh()->fee_fils);
        $this->assertFalse($off->fresh()->is_active);
        $this->assertNull(DeliveryArea::query()->find($remove->id));
        $this->assertSame(2_000, DeliveryArea::query()->where('name_en', 'Fresh')->firstOrFail()->fee_fils);
    }

    public function test_an_area_that_belongs_to_another_city_cannot_be_changed_from_this_page(): void
    {
        $mine = DeliveryCity::factory()->create();
        $theirs = DeliveryArea::factory()->create(['name_en' => 'Not yours', 'fee_fils' => 1_000]);

        $this->put(route('panel.delivery.cities.update', $mine), [
            'name_ar' => 'ا', 'name_en' => 'Mine', 'is_active' => '1',
            'areas' => [$theirs->id => ['name_ar' => 'ا', 'name_en' => 'Hijacked', 'fee' => '9', 'remove' => '1']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Not yours', $theirs->fresh()->name_en);
        $this->assertSame(1_000, $theirs->fresh()->fee_fils);
    }

    public function test_an_area_needs_names_and_a_fee_that_is_money_when_one_is_given(): void
    {
        $city = DeliveryCity::factory()->create();
        $area = DeliveryArea::factory()->for($city, 'city')->create();

        $this->put(route('panel.delivery.cities.update', $city), [
            'name_ar' => 'ا', 'name_en' => 'City',
            'areas' => [$area->id => ['name_ar' => '', 'name_en' => '', 'fee' => 'cheap']],
            'new_areas' => [['name_ar' => '', 'name_en' => 'X', 'fee' => '1.5555']],
        ])->assertSessionHasErrors(['areas.'.$area->id.'.name_ar', 'areas.'.$area->id.'.name_en', 'areas.'.$area->id.'.fee', 'new_areas.0.name_ar', 'new_areas.0.fee']);
    }

    public function test_one_fee_can_be_set_for_every_area_of_a_city_and_cleared_again(): void
    {
        $city = DeliveryCity::factory()->create();
        $other = DeliveryArea::factory()->create(['fee_fils' => 4_000]);
        DeliveryArea::factory()->count(3)->for($city, 'city')->create(['fee_fils' => null]);

        $this->post(route('panel.delivery.cities.fee', $city), ['fee' => '2.250'])->assertSessionHas('status');
        $this->assertSame([2_250], $city->areas()->pluck('fee_fils')->unique()->values()->all());
        $this->assertSame(4_000, $other->fresh()->fee_fils, 'another city is untouched');

        $this->post(route('panel.delivery.cities.fee', $city), ['fee' => ''])->assertSessionHas('status');
        $this->assertSame([null], $city->areas()->pluck('fee_fils')->unique()->values()->all());
    }

    public function test_deleting_a_city_takes_its_areas_but_past_orders_keep_where_they_went(): void
    {
        $city = DeliveryCity::factory()->create(['name_en' => 'Gone City']);
        $area = DeliveryArea::factory()->for($city, 'city')->create();
        $order = Order::factory()->create([
            'delivery_city_id' => $city->id, 'delivery_area_id' => $area->id, 'city_name_en' => 'Gone City', 'area_name_en' => 'Gone Area',
        ]);

        $this->delete(route('panel.delivery.cities.destroy', $city))->assertRedirect(route('panel.delivery.index'));

        $this->assertNull(DeliveryCity::query()->find($city->id));
        $this->assertSame(0, DeliveryArea::query()->count());

        $order->refresh();
        $this->assertNull($order->delivery_city_id);
        $this->assertSame('Gone City', $order->city_name_en);
        $this->assertSame('Gone Area', $order->area_name_en);
    }

    public function test_checkout_offers_only_active_cities_and_active_areas(): void
    {
        $open = DeliveryCity::factory()->create(['name_en' => 'Open City']);
        DeliveryArea::factory()->for($open, 'city')->create(['name_en' => 'Open Area']);
        DeliveryArea::factory()->for($open, 'city')->create(['name_en' => 'Closed Area', 'is_active' => false]);
        $closed = DeliveryCity::factory()->create(['name_en' => 'Closed City', 'is_active' => false]);
        DeliveryArea::factory()->for($closed, 'city')->create();

        $locations = app(Orders::class)->deliveryLocations();

        $this->assertSame(['Open City'], array_column($locations, 'name'));
        $this->assertSame(['Open Area'], array_column($locations[0]['areas'], 'name'));
    }

    public function test_a_city_that_does_not_exist_is_a_404(): void
    {
        $this->get(route('panel.delivery.cities.edit', 999))->assertNotFound();
        $this->delete(route('panel.delivery.cities.destroy', 999))->assertNotFound();
    }
}
