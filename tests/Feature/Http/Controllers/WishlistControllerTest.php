<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

class WishlistControllerTest extends TestCase
{
    use LazilyRefreshDatabase, UsesLocalStore;

    public function test_a_guest_saves_and_unsaves_a_product_in_the_session(): void
    {
        $product = Product::factory()->create();

        $saved = $this->postJson('/en-KW/wishlist/'.$product->id);
        $removed = $this->withSession(['wishlist.ids' => [(string) $product->id => (string) $product->id]])->postJson('/en-KW/wishlist/'.$product->id);

        $saved->assertOk()->assertJson(['ok' => true, 'saved' => true, 'count' => 1]);
        $removed->assertOk()->assertJson(['ok' => true, 'saved' => false, 'count' => 0]);
        $this->assertDatabaseCount('wishlist_items', 0);
    }

    public function test_a_signed_in_customer_saves_to_the_account_and_it_lasts(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['name_en' => 'Albustan']);
        $this->actingAs($customer, 'customer');

        $this->postJson('/en-KW/wishlist/'.$product->id)->assertJson(['saved' => true, 'count' => 1]);

        $this->assertDatabaseHas('wishlist_items', ['customer_id' => $customer->id, 'product_id' => $product->id]);
        $this->get('/en-KW/wishlist')->assertOk()->assertSee('Albustan');
    }

    public function test_a_product_that_does_not_exist_cannot_be_saved(): void
    {
        Product::factory()->inactive()->create(['id' => 7]);

        $unknown = $this->postJson('/en-KW/wishlist/999');
        $inactive = $this->postJson('/en-KW/wishlist/7');

        $unknown->assertJson(['saved' => false, 'count' => 0]);
        $inactive->assertJson(['saved' => false, 'count' => 0]);
    }

    public function test_what_a_guest_saved_follows_them_into_the_account_when_they_sign_in(): void
    {
        $customer = Customer::factory()->create(['email' => 'sara@example.com', 'password' => 'correct horse battery']);
        $already = Product::factory()->create();
        $guestOnly = Product::factory()->create();
        WishlistItem::factory()->create(['customer_id' => $customer->id, 'product_id' => $already->id]);

        $response = $this->withSession(['wishlist.ids' => [
            (string) $already->id => (string) $already->id,
            (string) $guestOnly->id => (string) $guestOnly->id,
            '999999' => '999999',
        ]])->post('/en-KW/login', ['email' => 'sara@example.com', 'password' => 'correct horse battery']);

        $response->assertSessionMissing('wishlist.ids');
        $this->assertEqualsCanonicalizing(
            [$already->id, $guestOnly->id],
            WishlistItem::query()->where('customer_id', $customer->id)->pluck('product_id')->all()
        );
    }

    public function test_one_customers_list_is_not_anothers(): void
    {
        $mine = Customer::factory()->create();
        $theirs = Customer::factory()->create();
        $product = Product::factory()->create();
        WishlistItem::factory()->create(['customer_id' => $theirs->id, 'product_id' => $product->id]);
        $this->actingAs($mine, 'customer');

        $this->postJson('/en-KW/wishlist/'.$product->id)->assertJson(['saved' => true, 'count' => 1]);
        $this->assertDatabaseCount('wishlist_items', 2);
    }
}
