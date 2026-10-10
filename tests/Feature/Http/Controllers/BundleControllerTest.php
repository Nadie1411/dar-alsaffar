<?php

namespace Tests\Feature\Http\Controllers;

use App\Contracts\Store\Cart;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

class BundleControllerTest extends TestCase
{
    use LazilyRefreshDatabase, UsesLocalStore;

    /** A package is a product with a required "choose three" group that nobody fills in. */
    private function package(): Product
    {
        $product = Product::factory()->create(['slug' => 'trio', 'name_en' => 'The Trio']);
        $group = OptionGroup::factory()->for($product)->checkbox(3, 3)->create();
        OptionValue::factory()->count(4)->for($group, 'group')->create();

        return $product;
    }

    public function test_the_package_page_is_the_product_page(): void
    {
        $this->package();

        $this->get('/en-KW/packages/trio')->assertRedirect('/en-KW/products/trio')->assertStatus(301);
        $this->get('/en-KW/packages/unknown')->assertNotFound();
    }

    public function test_a_package_is_bought_with_the_ordinary_buttons_and_nothing_to_pick(): void
    {
        $this->package();

        $this->get('/en-KW/products/trio')
            ->assertOk()
            ->assertSee('data-add-to-cart', false)
            ->assertSee('data-buy-bar', false)
            ->assertDontSee('data-option-value', false)
            ->assertDontSee('data-bundle', false);
    }

    public function test_a_package_adds_from_its_card_like_any_other_product(): void
    {
        $package = $this->package();

        $this->get('/en-KW/products')
            ->assertOk()
            ->assertSee('data-add-to-cart="'.$package->id.'"', false);
    }

    public function test_the_packages_list_links_to_the_product_page(): void
    {
        $this->package();

        $this->get('/en-KW/packages')
            ->assertOk()
            ->assertSee('/en-KW/products/trio', false)
            ->assertDontSee('/packages/trio', false);
    }

    public function test_a_package_can_be_added_without_choosing_and_the_cart_accepts_it(): void
    {
        $package = $this->package();

        $this->postJson('/en-KW/cart/add', ['productId' => (string) $package->id])
            ->assertOk()
            ->assertJson(['ok' => true, 'count' => 1]);

        $quote = $this->app->make(Cart::class)->quote();

        $this->assertSame([], $quote->problems());
        $this->assertTrue($quote->canPlaceOrder());
    }
}
