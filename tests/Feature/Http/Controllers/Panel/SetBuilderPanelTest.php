<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

/**
 * Staff set up a set the customer builds from the product form: the products
 * on offer as its pieces, how many the customer picks, and (in the product's
 * own price field) what the whole set costs.
 */
class SetBuilderPanelTest extends TestCase
{
    use LazilyRefreshDatabase, SignsInStaff;

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
            'name_ar' => 'ابنِ ثلاثيتك',
            'name_en' => 'Build a Trio',
            'price' => '14.000',
            'discount_type' => 'none',
            'is_active' => '1',
            'cod_enabled' => '1',
        ], $overrides);
    }

    /**
     * @param  array<int,Product>  $pieces
     * @return array{enabled:string,size:string,pieces:array<int,int>}
     */
    private function set(int $size, array $pieces): array
    {
        return ['enabled' => '1', 'size' => (string) $size, 'pieces' => array_map(fn (Product $piece) => $piece->id, $pieces)];
    }

    /** @return array<int,Product> */
    private function products(int $count = 5): array
    {
        return Product::factory()->count($count)->create()->all();
    }

    private function builderGroup(Product $product): ?OptionGroup
    {
        return $product->optionGroups()->where('is_builder', true)->with('values')->first();
    }

    // ------------------------------------------------------------- creating

    public function test_a_set_is_created_with_its_pieces_its_size_and_its_own_price(): void
    {
        $pieces = $this->products(4);

        $this->post(route('panel.products.store'), $this->form(['set' => $this->set(3, $pieces)]))
            ->assertSessionHasNoErrors();

        $product = Product::query()->where('name_en', 'Build a Trio')->firstOrFail();
        $group = $this->builderGroup($product);

        $this->assertSame(14_000, $product->sell_price_fils);
        $this->assertSame(['checkbox', true, true, 3, 3], [$group->layout, $group->is_required, $group->is_active, $group->min_choices, $group->max_choices]);
        $this->assertSame(array_map(fn (Product $piece) => $piece->id, $pieces), $group->values->pluck('product_id')->all());
        // The pieces add nothing: the set costs what the product's price says.
        $this->assertSame([0, 0, 0, 0], $group->values->pluck('price_fils')->all());
        $this->assertSame($pieces[0]->name_en, $group->values[0]->name_en);
    }

    public function test_the_form_shows_the_set_and_keeps_it_out_of_the_ordinary_option_groups(): void
    {
        $pieces = $this->products(3);
        $this->post(route('panel.products.store'), $this->form(['set' => $this->set(2, $pieces)]));
        $product = Product::query()->where('name_en', 'Build a Trio')->firstOrFail();

        $this->get(route('panel.products.edit', $product))
            ->assertOk()
            ->assertSee('Set the customer builds')
            ->assertSee('name="set[enabled]"', false)
            ->assertSee('value="'.$pieces[1]->id.'" checked', false)
            ->assertDontSee('options[0][name_en]', false);
    }

    public function test_a_set_cannot_be_offered_as_a_piece_of_another_set_or_of_itself(): void
    {
        $pieces = $this->products(3);
        $this->post(route('panel.products.store'), $this->form(['set' => $this->set(2, $pieces)]));
        $other = Product::query()->where('name_en', 'Build a Trio')->firstOrFail();

        $this->post(route('panel.products.store'), $this->form(['name_en' => 'Second', 'set' => $this->set(2, [$other, $pieces[0]])]))
            ->assertSessionHasErrors('set.pieces');

        $this->put(route('panel.products.update', $other), $this->form(['slug' => $other->slug, 'set' => $this->set(2, [$pieces[0], $pieces[1], $other])]))
            ->assertSessionHasErrors('set.pieces.2');
    }

    // ----------------------------------------------------------- validation

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function refusedSets(): array
    {
        return [
            'no size' => [['size' => ''], 'set.size'],
            'a set of one' => [['size' => '1'], 'set.size'],
            'too big a set' => [['size' => '21'], 'set.size'],
            'fewer products than pieces' => [['size' => '4'], 'set.pieces'],
            'no products' => [['pieces' => []], 'set.pieces'],
            'a product that does not exist' => [['pieces' => [999999, 999998, 999997]], 'set.pieces.0'],
        ];
    }

    /**
     * @param  array<string,mixed>  $change
     */
    #[DataProvider('refusedSets')]
    public function test_a_set_that_cannot_be_built_is_refused(array $change, string $errorOn): void
    {
        $set = array_merge($this->set(3, $this->products(3)), $change);

        $this->post(route('panel.products.store'), $this->form(['set' => $set]))->assertSessionHasErrors($errorOn);

        $this->assertSame(0, OptionGroup::query()->where('is_builder', true)->count());
        $this->assertNull(Product::query()->where('name_en', 'Build a Trio')->first());
    }

    public function test_a_product_cannot_be_offered_twice_in_one_set(): void
    {
        [$a, $b] = $this->products(2);

        $this->post(route('panel.products.store'), $this->form(['set' => $this->set(3, [$a, $a, $b])]))
            ->assertSessionHasErrors('set.pieces.0');
    }

    public function test_a_set_that_is_switched_off_is_not_checked(): void
    {
        $this->post(route('panel.products.store'), $this->form(['set' => ['size' => '', 'pieces' => []]]))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->builderGroup(Product::query()->where('name_en', 'Build a Trio')->firstOrFail()));
    }

    // -------------------------------------------------------------- editing

    public function test_changing_the_pieces_keeps_the_ones_that_stay_and_updates_the_size(): void
    {
        $pieces = $this->products(5);
        $set = Product::factory()->priced(14_000)->create();
        $group = OptionGroup::factory()->for($set)->builder(3)->create();
        $kept = OptionValue::factory()->for($group, 'group')->piece($pieces[0])->create();
        OptionValue::factory()->for($group, 'group')->piece($pieces[1])->create();

        $this->put(route('panel.products.update', $set), $this->form([
            'name_ar' => $set->name_ar, 'name_en' => $set->name_en, 'slug' => $set->slug,
            'set' => $this->set(2, [$pieces[0], $pieces[3], $pieces[4]]),
        ]))->assertSessionHasNoErrors();

        $group->refresh()->load('values');

        $this->assertSame([2, 2], [$group->min_choices, $group->max_choices]);
        $this->assertSame([$pieces[0]->id, $pieces[3]->id, $pieces[4]->id], $group->values->pluck('product_id')->all());
        $this->assertSame($kept->id, $group->values[0]->id);
        $this->assertSame(1, $set->optionGroups()->count());
    }

    public function test_switching_the_set_off_makes_it_an_ordinary_product_again(): void
    {
        $set = Product::factory()->create();
        $group = OptionGroup::factory()->for($set)->builder(2)->create();
        OptionValue::factory()->for($group, 'group')->piece($this->products(1)[0])->create();

        $this->put(route('panel.products.update', $set), $this->form(['name_ar' => $set->name_ar, 'name_en' => $set->name_en, 'slug' => $set->slug]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $set->optionGroups()->count());
        $this->assertSame(0, OptionValue::query()->count());
    }

    public function test_ordinary_options_can_be_saved_beside_a_set_and_never_touch_it(): void
    {
        $set = Product::factory()->create();
        $group = OptionGroup::factory()->for($set)->builder(2)->create();
        $piece = OptionValue::factory()->for($group, 'group')->piece($this->products(1)[0])->create();
        $pieces = array_map(fn (OptionValue $value) => $value->product_id, [$piece]);

        // A form that names the set's own group as if it were an ordinary one.
        $this->put(route('panel.products.update', $set), $this->form([
            'name_ar' => $set->name_ar, 'name_en' => $set->name_en, 'slug' => $set->slug,
            'set' => ['enabled' => '1', 'size' => '2', 'pieces' => [...$pieces, $this->products(1)[0]->id]],
            'options' => [[
                'id' => $group->id, 'name_ar' => 'الحجم', 'name_en' => 'Size', 'layout' => 'radio', 'is_required' => '1', 'is_active' => '1',
                'values' => [['name_ar' => '50', 'name_en' => '50 ml', 'price' => '0', 'is_active' => '1']],
            ]],
        ]))->assertSessionHasNoErrors();

        $builder = $this->builderGroup($set);
        $size = $set->optionGroups()->where('is_builder', false)->firstOrFail();

        $this->assertSame([$group->id, 'Your picks', 'checkbox'], [$builder->id, $builder->name_en, $builder->layout]);
        $this->assertSame(2, $builder->values->count());
        $this->assertSame(['Size', 'radio'], [$size->name_en, $size->layout]);
        $this->assertNotSame($group->id, $size->id);
    }

    public function test_a_copy_of_a_set_is_a_set_with_the_same_pieces(): void
    {
        $set = Product::factory()->create();
        $group = OptionGroup::factory()->for($set)->builder(2)->create();
        $pieces = $this->products(3);

        foreach ($pieces as $piece) {
            OptionValue::factory()->for($group, 'group')->piece($piece)->create();
        }

        $this->post(route('panel.products.duplicate', $set))->assertSessionHasNoErrors();

        $copy = Product::query()->whereKeyNot($set->id)->where('slug', 'like', '%-copy%')->firstOrFail();
        $copied = $this->builderGroup($copy);

        $this->assertFalse($copy->is_active);
        $this->assertSame(2, $copied->max_choices);
        $this->assertSame(collect($pieces)->pluck('id')->all(), $copied->values->pluck('product_id')->all());
    }

    public function test_deleting_a_product_takes_it_out_of_the_sets_it_was_a_piece_of(): void
    {
        $set = Product::factory()->create();
        $group = OptionGroup::factory()->for($set)->builder(2)->create();
        [$a, $b, $c] = $this->products(3);

        foreach ([$a, $b, $c] as $piece) {
            OptionValue::factory()->for($group, 'group')->piece($piece)->create();
        }

        $this->delete(route('panel.products.destroy', $b))->assertSessionHasNoErrors();

        $this->assertSame([$a->id, $c->id], $group->values()->pluck('product_id')->all());
    }
}
