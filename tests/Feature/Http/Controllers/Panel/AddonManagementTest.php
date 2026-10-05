<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Contracts\Store\Orders;
use App\Models\ServiceAddon;
use App\Services\Store\Pricing\PricingContext;
use App\Services\Store\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\IsolatesUploads;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class AddonManagementTest extends TestCase
{
    use IsolatesUploads, LazilyRefreshDatabase, SignsInStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signInAs('manager');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name_ar' => 'تغليف هدية', 'name_en' => 'Gift wrapping', 'price' => '1.500', 'sort_order' => '1', 'is_active' => '1',
        ], $overrides);
    }

    public function test_the_list_shows_every_service_with_its_price_and_state(): void
    {
        ServiceAddon::factory()->create(['name_en' => 'Gift wrapping', 'price_fils' => 1_500]);
        ServiceAddon::factory()->create(['name_en' => 'Engraving', 'price_fils' => 3_000, 'is_active' => false]);

        $this->get(route('panel.addons.index'))
            ->assertOk()
            ->assertSee('Gift wrapping')->assertSee('1.500 KWD')
            ->assertSee('Engraving')->assertSee('3.000 KWD')->assertSee('Inactive');
    }

    public function test_a_service_is_created_with_its_price_in_whole_fils_and_a_picture(): void
    {
        $this->post(route('panel.addons.store'), $this->form([
            'price' => '٢٫٧٥٠', 'description_en' => 'A ribbon', 'image' => UploadedFile::fake()->image('ribbon.jpg', 300, 300),
        ]))->assertRedirect(route('panel.addons.index'));

        $addon = ServiceAddon::query()->firstOrFail();

        $this->assertSame(2_750, $addon->price_fils);
        $this->assertSame('A ribbon', $addon->description_en);
        $this->assertNull($addon->description_ar);
        $this->assertTrue($addon->is_active);
        $this->assertTrue($this->storedFileExists($addon->image));
        $this->assertDatabaseHas('activity_logs', ['action' => 'addon.created']);
    }

    public function test_a_price_that_is_not_money_is_refused(): void
    {
        foreach (['', 'free', '1.5555', '-1'] as $price) {
            $this->post(route('panel.addons.store'), $this->form(['price' => $price]))->assertSessionHasErrors('price');
        }

        $this->assertSame(0, ServiceAddon::query()->count());
    }

    public function test_a_free_service_is_allowed(): void
    {
        $this->post(route('panel.addons.store'), $this->form(['price' => '0']))->assertSessionHasNoErrors();

        $this->assertSame(0, ServiceAddon::query()->firstOrFail()->price_fils);
    }

    public function test_a_service_can_be_edited_switched_off_and_have_its_picture_replaced(): void
    {
        $old = $this->existingUpload('uploads/addons/old.jpg');
        $addon = ServiceAddon::factory()->create(['image' => $old]);

        $this->put(route('panel.addons.update', $addon), $this->form([
            'name_en' => 'Premium wrapping', 'price' => '2.000', 'is_active' => null, 'image' => UploadedFile::fake()->image('new.jpg', 300, 300),
        ]))->assertRedirect(route('panel.addons.index'));

        $addon->refresh();
        $this->assertSame('Premium wrapping', $addon->name_en);
        $this->assertSame(2_000, $addon->price_fils);
        $this->assertFalse($addon->is_active);
        $this->assertNotSame($old, $addon->image);
        $this->assertFalse($this->storedFileExists($old));
    }

    public function test_a_service_can_be_deleted_with_its_picture(): void
    {
        $file = $this->existingUpload('uploads/addons/gone.jpg');
        $addon = ServiceAddon::factory()->create(['image' => $file]);

        $this->delete(route('panel.addons.destroy', $addon))->assertRedirect(route('panel.addons.index'));

        $this->assertNull(ServiceAddon::query()->find($addon->id));
        $this->assertFalse($this->storedFileExists($file));
        $this->assertDatabaseHas('activity_logs', ['action' => 'addon.deleted', 'subject_label' => 'تغليف هدية']);
    }

    public function test_a_service_that_does_not_exist_is_a_404(): void
    {
        $this->get(route('panel.addons.edit', 999))->assertNotFound();
    }

    public function test_checkout_offers_exactly_the_services_that_are_switched_on_and_prices_them_as_entered(): void
    {
        $this->post(route('panel.addons.store'), $this->form(['name_en' => 'Gift wrapping', 'price' => '1.500']));
        $this->post(route('panel.addons.store'), $this->form(['name_en' => 'Hidden extra', 'price' => '9.000', 'is_active' => null]));

        $offered = collect(app(Orders::class)->serviceAddons());
        $this->assertSame(['Gift wrapping'], $offered->pluck('name')->all());

        $wrapping = ServiceAddon::query()->where('name_en', 'Gift wrapping')->firstOrFail();
        $priced = app(PricingEngine::class)->price([], new PricingContext(addonIds: [$wrapping->id]));

        $this->assertSame(1_500, $priced->addonsTotalFils);
    }
}
