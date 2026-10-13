<?php

namespace Tests\Feature\Http\Controllers;

use App\Contracts\Store\Cart;
use App\Models\DeliveryArea;
use App\Models\DeliveryCity;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\IsolatesSettings;
use Tests\Concerns\UsesLocalStore;
use Tests\TestCase;

/**
 * A set the customer builds: staff offer some products as its pieces for one
 * price, and the customer picks a fixed number of them on the set's own page.
 */
class CustomerBuiltSetTest extends TestCase
{
    use IsolatesSettings, LazilyRefreshDatabase, UsesLocalStore;

    private Product $set;

    private OptionGroup $group;

    /** @var array<int,Product> */
    private array $pieces = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->set = Product::factory()->priced(14_000)->create(['slug' => 'trio', 'name_en' => 'Build a Trio', 'name_ar' => 'ابنِ ثلاثيتك']);
        $this->group = OptionGroup::factory()->for($this->set)->builder(3)->create();

        foreach (['Sky' => 35_000, 'Moon' => 14_000, 'Green' => 21_000, 'Rose' => 7_000] as $name => $price) {
            $piece = Product::factory()->priced($price)->create(['name_en' => $name, 'name_ar' => 'عطر '.$name]);
            OptionValue::factory()->for($this->group, 'group')->piece($piece)->create(['sort_order' => count($this->pieces)]);
            $this->pieces[$name] = $piece;
        }
    }

    private function value(string $piece): OptionValue
    {
        return $this->group->values()->where('product_id', $this->pieces[$piece]->id)->firstOrFail();
    }

    /** @return array<int,string> the value ids of the named pieces */
    private function picks(string ...$names): array
    {
        return array_map(fn (string $name) => (string) $this->value($name)->id, $names);
    }

    private function build(string ...$names): TestResponse
    {
        return $this->post('/en-KW/packages/trio', ['values' => $this->picks(...$names)]);
    }

    // ------------------------------------------------------------- the pages

    public function test_the_set_has_a_page_where_the_customer_picks_its_pieces(): void
    {
        $this->get('/en-KW/packages/trio')
            ->assertOk()
            ->assertSee('data-bundle', false)
            ->assertSee('data-size="3"', false)
            ->assertSee('Choose product 1 of 3')
            ->assertSee('data-track', false)
            ->assertSee('Choose this one')
            ->assertSee('Sky')
            ->assertSee('Moon')
            ->assertSee('Green')
            ->assertSee('Rose')
            ->assertSee('14 KWD');
    }

    public function test_a_piece_that_is_switched_off_is_not_offered_and_one_sold_out_cannot_be_picked(): void
    {
        $this->pieces['Rose']->update(['is_active' => false]);
        $this->pieces['Moon']->update(['track_stock' => true, 'stock' => 0]);

        $this->get('/en-KW/packages/trio')
            ->assertOk()
            ->assertDontSee('Rose')
            ->assertSee('Moon')
            ->assertSee('data-sold-out', false);
    }

    public function test_a_piece_is_shown_under_the_products_current_name(): void
    {
        $this->pieces['Sky']->update(['name_en' => 'Sky Intense']);

        $this->get('/en-KW/packages/trio')->assertSee('Sky Intense');
    }

    public function test_the_sets_product_page_and_card_lead_to_the_builder_instead_of_adding(): void
    {
        $this->get('/en-KW/products/trio')
            ->assertOk()
            ->assertSee('/en-KW/packages/trio', false)
            ->assertDontSee('data-add-to-cart="'.$this->set->id.'"', false)
            ->assertDontSee('data-buy-bar', false);

        $this->get('/en-KW/products')
            ->assertOk()
            ->assertDontSee('data-add-to-cart="'.$this->set->id.'"', false)
            ->assertSee('data-add-to-cart="'.$this->pieces['Sky']->id.'"', false);
    }

    public function test_the_packages_list_opens_the_builder_for_a_set(): void
    {
        $this->get('/en-KW/packages')
            ->assertOk()
            ->assertSee('/en-KW/packages/trio', false)
            ->assertSee('Build your set');
    }

    public function test_a_set_with_too_few_pieces_left_cannot_be_built(): void
    {
        $this->pieces['Sky']->delete();
        $this->pieces['Moon']->update(['is_active' => false]);

        $this->get('/en-KW/packages/trio')->assertOk()->assertSee('This package is not available right now.');

        $this->post('/en-KW/packages/trio', ['values' => $this->picks('Green', 'Rose')])->assertSessionHasErrors('values');
        $this->assertTrue($this->app->make(Cart::class)->isEmpty());
    }

    // ------------------------------------------------------------- building

    public function test_the_set_goes_in_the_bag_at_its_own_price_whichever_pieces_are_picked(): void
    {
        $this->build('Sky', 'Green', 'Rose')->assertRedirect('/en-KW/cart');

        $quote = $this->app->make(Cart::class)->quote();
        $this->assertSame([], $quote->problems());
        $this->assertCount(1, $quote->lines());
        // Sky alone is 35 KWD; the three together cost the set's 14 KWD.
        $this->assertSame(14.0, $quote->subTotal());

        $this->get('/en-KW/cart')
            ->assertOk()
            ->assertSee('Build a Trio')
            ->assertSee('Your picks')
            ->assertSee('Sky, Green, Rose');
    }

    public function test_the_same_pieces_are_one_line_and_different_pieces_are_another(): void
    {
        $this->build('Sky', 'Moon', 'Rose');
        $this->build('Rose', 'Moon', 'Sky');
        $this->build('Sky', 'Moon', 'Green');

        $this->assertCount(2, $this->app->make(Cart::class)->items());
        $this->assertSame(3, $this->app->make(Cart::class)->count());
    }

    /** @return array<string,array{0:array<int,string>,1:string}> the pieces picked, and where the error shows */
    public static function badPicks(): array
    {
        return [
            'too few' => [['Sky', 'Moon'], 'values'],
            'too many' => [['Sky', 'Moon', 'Green', 'Rose'], 'values'],
            'the same piece twice' => [['Sky', 'Sky', 'Moon'], 'values.0'],
        ];
    }

    /** @param  array<int,string>  $names */
    #[DataProvider('badPicks')]
    public function test_a_set_that_is_not_filled_exactly_is_refused(array $names, string $errorOn): void
    {
        $this->build(...$names)->assertSessionHasErrors($errorOn);

        $this->assertTrue($this->app->make(Cart::class)->isEmpty());
    }

    public function test_a_piece_from_somewhere_else_or_sold_out_is_refused(): void
    {
        $stranger = OptionValue::factory()->for(OptionGroup::factory()->for(Product::factory())->create(), 'group')->create();
        $this->pieces['Moon']->update(['track_stock' => true, 'stock' => 0]);

        $this->post('/en-KW/packages/trio', ['values' => [...$this->picks('Sky', 'Green'), (string) $stranger->id]])
            ->assertSessionHasErrors('values.2');
        $this->build('Sky', 'Green', 'Moon')->assertSessionHasErrors('values.2');

        $this->assertTrue($this->app->make(Cart::class)->isEmpty());
    }

    public function test_a_set_cannot_be_bought_without_choosing_its_pieces(): void
    {
        $this->postJson('/en-KW/cart/add', ['productId' => (string) $this->set->id])->assertOk();

        $quote = $this->app->make(Cart::class)->quote();

        $this->assertFalse($quote->canPlaceOrder());
        $this->assertFalse($quote->lines()[0]['available']);
    }

    public function test_a_page_that_is_not_a_set_has_no_builder(): void
    {
        $this->get('/en-KW/packages/'.$this->pieces['Sky']->slug)->assertRedirect('/en-KW/products/'.$this->pieces['Sky']->slug);
        $this->post('/en-KW/packages/'.$this->pieces['Sky']->slug, ['values' => []])->assertNotFound();
    }

    // ------------------------------------------------- what changes after adding

    public function test_a_piece_switched_off_after_adding_makes_the_line_unavailable(): void
    {
        $this->build('Sky', 'Moon', 'Rose');
        $this->pieces['Moon']->update(['is_active' => false]);

        $quote = $this->app->make(Cart::class)->quote();

        $this->assertFalse($quote->canPlaceOrder());
        $this->assertFalse($quote->lines()[0]['available']);
    }

    public function test_a_piece_with_less_stock_than_the_sets_ordered_blocks_the_line_and_says_which(): void
    {
        $this->build('Sky', 'Moon', 'Rose');
        $this->pieces['Moon']->update(['track_stock' => true, 'stock' => 1]);
        $this->app->make(Cart::class)->updateQuantity(array_key_first($this->app->make(Cart::class)->items()), 2);

        $line = $this->app->make(Cart::class)->quote()->lines()[0];

        $this->assertFalse($line['available']);
        $this->assertSame('Moon is not available right now', $line['message']);
    }

    // ------------------------------------------------------------- the order

    public function test_the_order_records_the_pieces_and_takes_each_off_the_shelf(): void
    {
        $this->pieces['Sky']->update(['track_stock' => true, 'stock' => 5]);
        $this->pieces['Rose']->update(['track_stock' => true, 'stock' => 5]);
        $this->pieces['Green']->update(['track_stock' => true, 'stock' => 5]);

        $this->build('Sky', 'Moon', 'Rose');
        $this->app->make(Cart::class)->updateQuantity(array_key_first($this->app->make(Cart::class)->items()), 2);

        $area = DeliveryArea::factory()->for(DeliveryCity::factory(), 'city')->create(['fee_fils' => 2_000]);

        $this->post('/en-KW/checkout', [
            'fullName' => 'Sara Al-Ahmad', 'phone' => '51234567', 'city' => (string) $area->delivery_city_id, 'area' => (string) $area->id,
            'block' => '4', 'street' => 'Street 12', 'building' => '7', 'payment' => 'cod',
        ])->assertSessionHasNoErrors();

        $order = Order::query()->with('items')->firstOrFail();
        $item = $order->items->firstOrFail();

        // Two sets at 14 KWD each, whichever pieces.
        $this->assertSame([2, 14_000, 28_000], [$item->quantity, $item->unit_price_fils, $item->total_fils]);
        $this->assertSame('Your picks', $item->options[0]['group']['en']);
        $this->assertSame(['Sky', 'Moon', 'Rose'], array_map(fn (array $value) => $value['name']['en'], $item->options[0]['values']));

        // Two sets took two of each piece picked, and nothing of the one left out.
        $this->assertSame([3, 3, 5], [
            $this->pieces['Sky']->refresh()->stock, $this->pieces['Rose']->refresh()->stock, $this->pieces['Green']->refresh()->stock,
        ]);
    }

    public function test_an_order_is_refused_when_a_piece_ran_out_between_the_bag_and_the_checkout(): void
    {
        $this->pieces['Sky']->update(['track_stock' => true, 'stock' => 1]);
        $this->build('Sky', 'Moon', 'Rose');
        $this->pieces['Sky']->update(['stock' => 0]);

        $area = DeliveryArea::factory()->for(DeliveryCity::factory(), 'city')->create(['fee_fils' => 2_000]);

        $this->post('/en-KW/checkout', [
            'fullName' => 'Sara Al-Ahmad', 'phone' => '51234567', 'city' => (string) $area->delivery_city_id, 'area' => (string) $area->id,
            'block' => '4', 'street' => 'Street 12', 'building' => '7', 'payment' => 'cod',
        ]);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, $this->pieces['Sky']->refresh()->stock);
    }

    public function test_the_checkout_summary_lists_the_pieces(): void
    {
        $this->build('Sky', 'Moon', 'Rose');
        $this->assertSame(1, $this->app->make(Cart::class)->count());

        $this->get('/en-KW/checkout')->assertOk()->assertSee('Sky, Moon, Rose');
    }
}
