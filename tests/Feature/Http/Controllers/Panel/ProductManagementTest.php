<?php

namespace Tests\Feature\Http\Controllers\Panel;

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductQuantityTier;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\Store\Pricing\PricingContext;
use App\Services\Store\Pricing\PricingEngine;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\IsolatesUploads;
use Tests\Concerns\SignsInStaff;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use IsolatesUploads, LazilyRefreshDatabase, SignsInStaff;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->signInAs('manager');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name_ar' => 'عطر الملوك',
            'name_en' => 'Kings Perfume',
            'price' => '12.500',
            'discount_type' => 'none',
            'track_stock' => '1',
            'stock' => '10',
            'low_stock_threshold' => '3',
            'is_active' => '1',
            'cod_enabled' => '1',
        ], $overrides);
    }

    /**
     * @return array<string,mixed>
     */
    private function sizeOptions(): array
    {
        return [[
            'name_ar' => 'الحجم', 'name_en' => 'Size', 'layout' => 'radio', 'is_required' => '1', 'is_active' => '1',
            'values' => [
                ['name_ar' => '50 مل', 'name_en' => '50 ml', 'price' => '0', 'is_active' => '1'],
                ['name_ar' => '100 مل', 'name_en' => '100 ml', 'price' => '5.500', 'is_active' => '1'],
            ],
        ]];
    }

    private function jpeg(string $name = 'bottle.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 600, 600);
    }

    // ------------------------------------------------------------------ list

    public function test_the_list_can_be_searched_and_narrowed_by_category_status_and_stock(): void
    {
        $oud = Category::factory()->create();
        $match = Product::factory()->create(['name_en' => 'Royal Oud', 'sku' => 'OUD-1']);
        $match->categories()->attach($oud);
        Product::factory()->create(['name_en' => 'Plain Musk']);
        Product::factory()->inactive()->create(['name_en' => 'Hidden Amber']);
        Product::factory()->withStock(0)->create(['name_en' => 'Sold Out One']);
        Product::factory()->withStock(2, 3)->create(['name_en' => 'Almost Gone']);

        $names = fn (array $query) => $this->get(route('panel.products.index', $query))->viewData('products')->pluck('name_en')->all();

        $this->assertSame(['Royal Oud'], $names(['q' => 'royal']));
        $this->assertSame(['Royal Oud'], $names(['q' => 'OUD-1']));
        $this->assertSame(['Royal Oud'], $names(['category' => (string) $oud->id]));
        $this->assertSame(['Hidden Amber'], $names(['status' => 'inactive']));
        $this->assertSame(['Sold Out One'], $names(['stock' => 'out']));
        $this->assertSame(['Almost Gone'], $names(['stock' => 'low']));
        $this->assertCount(3, $names(['stock' => 'untracked']));
    }

    public function test_the_list_pages_at_twenty_and_ignores_filters_it_does_not_know(): void
    {
        Product::factory()->count(22)->create();

        $this->assertCount(20, $this->get(route('panel.products.index', ['status' => 'bogus', 'stock' => 'bogus', 'category' => 'x']))->viewData('products'));
        $this->assertCount(2, $this->get(route('panel.products.index', ['page' => 2]))->viewData('products'));
    }

    // ---------------------------------------------------------------- create

    public function test_a_product_is_created_with_everything_the_form_describes(): void
    {
        $category = Category::factory()->create();
        $other = Product::factory()->create();
        $second = Product::factory()->create();

        $response = $this->post(route('panel.products.store'), $this->form([
            'description_ar' => 'وصف عطر الملوك',
            'description_en' => "Line one\n\nLine two",
            'sku' => ' KP-50 ',
            'tags' => 'oud, luxury ،gift, oud',
            'video' => 'https://cdn.example.com/kings.mp4',
            'discount_type' => 'percentage',
            'discount_percent' => '10',
            'discount_starts_at' => '2026-10-10T09:00',
            'discount_ends_at' => '2026-10-20T23:59',
            'max_per_order' => '4',
            'is_featured' => '1',
            'is_new' => '1',
            'sort_order' => '7',
            'categories' => [$category->id],
            'related' => [$second->id, $other->id],
            'options' => $this->sizeOptions(),
            'tiers' => [['min_quantity' => '3', 'discount_type' => 'fixed', 'discount_amount' => '2.500', 'label_ar' => 'ثلاث', 'label_en' => 'Three', 'free_delivery' => '1']],
        ]));

        $product = Product::query()->where('name_en', 'Kings Perfume')->firstOrFail();
        $response->assertRedirect(route('panel.products.edit', $product));

        $this->assertSame('kings-perfume', $product->slug);
        $this->assertSame('KP-50', $product->sku);
        $this->assertSame('Line one'."\n\n".'Line two', $product->description_en);
        $this->assertSame(12_500, $product->sell_price_fils);
        $this->assertSame(Product::DISCOUNT_PERCENTAGE, $product->discount_type);
        $this->assertSame('10.00', $product->discount_percent);
        $this->assertSame(0, $product->discount_fils);
        // Typed in Kuwait time (UTC+3), stored in UTC.
        $this->assertSame('2026-10-10 06:00:00', $product->discount_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-20 20:59:00', $product->discount_ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($product->track_stock);
        $this->assertSame(10, $product->stock);
        $this->assertSame(3, $product->low_stock_threshold);
        $this->assertSame(4, $product->max_per_order);
        $this->assertSame(['oud', 'luxury', 'gift'], $product->tags);
        $this->assertSame('https://cdn.example.com/kings.mp4', $product->video);
        $this->assertTrue($product->is_active && $product->is_featured && $product->is_new && $product->cod_enabled);
        $this->assertFalse($product->is_popular);
        $this->assertSame(7, $product->sort_order);
        $this->assertSame([$category->id], $product->categories->modelKeys());
        $this->assertSame([$second->id, $other->id], $product->related->modelKeys());

        $group = $product->optionGroups()->with('values')->firstOrFail();
        $this->assertSame('Size', $group->name_en);
        $this->assertTrue($group->is_required);
        $this->assertSame([0, 5_500], $group->values->pluck('price_fils')->all());
        $this->assertSame(['50 ml', '100 ml'], $group->values->pluck('name_en')->all());

        $tier = $product->quantityTiers()->firstOrFail();
        $this->assertSame([3, 'fixed', 2_500, true], [$tier->min_quantity, $tier->discount_type, $tier->discount_fils, $tier->free_delivery]);

        $this->assertDatabaseHas('activity_logs', ['user_id' => $this->manager->id, 'action' => 'product.created', 'subject_label' => 'عطر الملوك']);
    }

    public function test_money_may_be_typed_with_arabic_digits_or_a_decimal_comma(): void
    {
        $this->post(route('panel.products.store'), $this->form([
            'price' => '١٢٫٥',
            'discount_type' => 'fixed',
            'discount_amount' => '2,5',
            'options' => [[
                'name_ar' => 'الحجم', 'name_en' => 'Size', 'layout' => 'radio', 'is_active' => '1',
                'values' => [['name_ar' => 'كبير', 'name_en' => 'Large', 'price' => '٥٫٥٠٠', 'is_active' => '1']],
            ]],
        ]))->assertSessionHasNoErrors();

        $product = Product::query()->where('name_en', 'Kings Perfume')->firstOrFail();

        $this->assertSame(12_500, $product->sell_price_fils);
        $this->assertSame(2_500, $product->discount_fils);
        $this->assertSame(5_500, $product->optionGroups()->firstOrFail()->values()->firstOrFail()->price_fils);
    }

    public function test_a_product_made_here_is_priced_by_the_engine_exactly_as_the_form_described_it(): void
    {
        $this->post(route('panel.products.store'), $this->form([
            'price' => '20.000',
            'discount_type' => 'percentage', 'discount_percent' => '10',
            'track_stock' => '1', 'stock' => '9',
            'options' => $this->sizeOptions(),
            'tiers' => [['min_quantity' => '2', 'discount_type' => 'fixed', 'discount_amount' => '3.000']],
        ]))->assertSessionHasNoErrors();

        $product = Product::query()->where('name_en', 'Kings Perfume')->with('optionGroups.values')->firstOrFail();
        $group = $product->optionGroups->first();
        $large = $group->values->firstWhere('name_en', '100 ml');

        $priced = app(PricingEngine::class)->price(['line' => [
            'productId' => (string) $product->id,
            'quantity' => 2,
            'options' => [['optionId' => (string) $group->id, 'values' => [['valueId' => (string) $large->id, 'quantity' => 1]]]],
        ]], new PricingContext);

        // 20.000 less 10% is 18.000, plus the 5.500 size, twice, less the 3.000 two-pack discount.
        $this->assertSame(23_500 * 2 - 3_000, $priced->subTotalFils);
        $this->assertTrue($priced->lines[0]->available);
    }

    public function test_a_new_products_pictures_are_stored_under_random_names_with_their_real_type(): void
    {
        $this->post(route('panel.products.store'), $this->form([
            'main_image' => $this->jpeg('..bad name.jpg'),
            'gallery' => [$this->jpeg('one.jpg'), UploadedFile::fake()->image('two.png', 400, 400)],
        ]))->assertSessionHasNoErrors();

        $product = Product::query()->where('name_en', 'Kings Perfume')->with('images')->firstOrFail();

        $this->assertMatchesRegularExpression('#^uploads/catalog/[A-Za-z0-9]{32}\.jpg$#', $product->main_image);
        $this->assertTrue($this->storedFileExists($product->main_image));
        $this->assertCount(2, $product->images);
        $this->assertStringEndsWith('.jpg', $product->images[0]->path);
        $this->assertStringEndsWith('.png', $product->images[1]->path);
        $this->assertSame([1, 2], $product->images->pluck('sort_order')->all());
        $this->assertStringNotContainsString('bad', $product->main_image);
    }

    public function test_a_product_with_a_gallery_but_no_main_picture_borrows_the_first_one(): void
    {
        $this->post(route('panel.products.store'), $this->form(['gallery' => [$this->jpeg('one.jpg')]]));

        $product = Product::query()->where('name_en', 'Kings Perfume')->with('images')->firstOrFail();

        $this->assertSame($product->images->first()->path, $product->main_image);
    }

    public function test_only_pictures_are_accepted_and_not_too_big_or_too_many(): void
    {
        foreach ([
            ['main_image' => UploadedFile::fake()->create('shell.php', 10, 'application/x-php')],
            ['main_image' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            ['main_image' => UploadedFile::fake()->create('big.jpg', 6_000, 'image/jpeg')],
            ['main_image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')],
        ] as $upload) {
            $this->post(route('panel.products.store'), $this->form($upload))->assertSessionHasErrors('main_image');
        }

        $thirteen = array_map(fn ($n) => $this->jpeg("g$n.jpg"), range(1, 13));
        $this->post(route('panel.products.store'), $this->form(['gallery' => $thirteen]))->assertSessionHasErrors('gallery');

        $this->assertSame(0, Product::query()->count());
    }

    public function test_a_slug_is_made_from_the_english_name_and_made_unique(): void
    {
        $this->post(route('panel.products.store'), $this->form());
        $this->post(route('panel.products.store'), $this->form());
        $this->post(route('panel.products.store'), $this->form(['slug' => '  My Custom_Slug!! ']));
        $this->post(route('panel.products.store'), $this->form(['name_en' => 'عطر', 'name_ar' => 'عطر']));

        $slugs = Product::query()->orderBy('id')->pluck('slug')->all();

        $this->assertSame('kings-perfume', $slugs[0]);
        $this->assertSame('kings-perfume-2', $slugs[1]);
        $this->assertSame('my-custom-slug', $slugs[2]);
        $this->assertNotSame('', $slugs[3]);
    }

    public function test_a_slug_already_taken_by_another_product_is_refused(): void
    {
        Product::factory()->create(['slug' => 'taken']);

        $this->post(route('panel.products.store'), $this->form(['slug' => 'taken']))->assertSessionHasErrors('slug');
    }

    public function test_descriptions_typed_as_plain_text_are_kept_and_markup_is_cleaned(): void
    {
        $this->post(route('panel.products.store'), $this->form([
            'description_ar' => "سطر أول\nسطر ثان",
            'description_en' => '<p onclick="steal()">Hello <b>world</b></p><script>alert(1)</script><a href="javascript:alert(1)">x</a><iframe src="//evil"></iframe>',
        ]));

        $product = Product::query()->firstOrFail();

        $this->assertSame("سطر أول\nسطر ثان", $product->description_ar);
        $this->assertStringContainsString('<b>world</b>', $product->description_en);
        $this->assertStringNotContainsString('onclick', $product->description_en);
        $this->assertStringNotContainsString('<script', $product->description_en);
        $this->assertStringNotContainsString('javascript:', $product->description_en);
        $this->assertStringNotContainsString('iframe', $product->description_en);
    }

    public function test_plain_text_descriptions_reach_the_storefront_as_paragraphs(): void
    {
        $this->post(route('panel.products.store'), $this->form(['description_en' => "First para\n\nSecond para"]));

        $page = $this->get('/en-KW/products/kings-perfume')->assertOk();

        $page->assertSee('<p>First para</p><p>Second para</p>', false);
    }

    // ------------------------------------------------------------ validation

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function invalidForms(): array
    {
        $values = [['name_ar' => 'ا', 'name_en' => 'a', 'price' => '0', 'is_active' => '1']];

        return [
            'no Arabic name' => [['name_ar' => ''], 'name_ar'],
            'no English name' => [['name_en' => ''], 'name_en'],
            'no price' => [['price' => ''], 'price'],
            'a price that is not a number' => [['price' => 'abc'], 'price'],
            'a price with four decimals' => [['price' => '12.5555'], 'price'],
            'a negative price' => [['price' => '-5'], 'price'],
            'a fixed discount with no amount' => [['discount_type' => 'fixed'], 'discount_amount'],
            'a fixed discount larger than the price' => [['discount_type' => 'fixed', 'discount_amount' => '13'], 'discount_amount'],
            'a percentage discount with no percentage' => [['discount_type' => 'percentage'], 'discount_percent'],
            'a percentage discount of zero' => [['discount_type' => 'percentage', 'discount_percent' => '0'], 'discount_percent'],
            'a percentage over a hundred' => [['discount_type' => 'percentage', 'discount_percent' => '101'], 'discount_percent'],
            'a discount that ends before it starts' => [['discount_type' => 'percentage', 'discount_percent' => '5', 'discount_starts_at' => '2026-10-10T10:00', 'discount_ends_at' => '2026-10-09T10:00'], 'discount_ends_at'],
            'an unknown discount type' => [['discount_type' => 'bogo'], 'discount_type'],
            'a stock that is negative' => [['stock' => '-1'], 'stock'],
            'a limit per order of zero' => [['max_per_order' => '0'], 'max_per_order'],
            'a video that is not a web address' => [['video' => 'javascript:alert(1)'], 'video'],
            'a category that does not exist' => [['categories' => [9999]], 'categories.0'],
            'a related product that does not exist' => [['related' => [9999]], 'related.0'],
            'an option group with no values' => [['options' => [['name_ar' => 'ا', 'name_en' => 'a', 'layout' => 'radio', 'values' => []]]], 'options.0.values'],
            'an option group with no name' => [['options' => [['name_ar' => '', 'name_en' => 'a', 'layout' => 'radio', 'values' => $values]]], 'options.0.name_ar'],
            'an option layout that does not exist' => [['options' => [['name_ar' => 'ا', 'name_en' => 'a', 'layout' => 'dropdown', 'values' => $values]]], 'options.0.layout'],
            'an option price that is not money' => [['options' => [['name_ar' => 'ا', 'name_en' => 'a', 'layout' => 'radio', 'values' => [['name_ar' => 'ا', 'name_en' => 'a', 'price' => 'free']]]]], 'options.0.values.0.price'],
            'a most below the fewest' => [['options' => [['name_ar' => 'ا', 'name_en' => 'a', 'layout' => 'checkbox', 'min_choices' => '3', 'max_choices' => '2', 'values' => $values]]], 'options.0.max_choices'],
            'a fewest above the number of values' => [['options' => [['name_ar' => 'ا', 'name_en' => 'a', 'layout' => 'checkbox', 'min_choices' => '2', 'max_choices' => '0', 'values' => $values]]], 'options.0.min_choices'],
            'a tier with a quantity of zero' => [['tiers' => [['min_quantity' => '0', 'discount_type' => 'none']]], 'tiers.0.min_quantity'],
            'two tiers at the same quantity' => [['tiers' => [['min_quantity' => '2', 'discount_type' => 'none'], ['min_quantity' => '2', 'discount_type' => 'none']]], 'tiers.0.min_quantity'],
            'a fixed tier with no amount' => [['tiers' => [['min_quantity' => '2', 'discount_type' => 'fixed']]], 'tiers.0.discount_amount'],
            'a percentage tier with no percentage' => [['tiers' => [['min_quantity' => '2', 'discount_type' => 'percentage']]], 'tiers.0.discount_percent'],
        ];
    }

    /**
     * @param  array<string,mixed>  $override
     */
    #[DataProvider('invalidForms')]
    public function test_a_form_that_does_not_add_up_is_refused_and_nothing_is_saved(array $override, string $field): void
    {
        $this->post(route('panel.products.store'), $this->form($override))->assertSessionHasErrors($field);

        $this->assertSame(0, Product::query()->count());
        $this->assertSame(0, OptionGroup::query()->count());
    }

    public function test_a_refused_form_comes_back_with_what_was_typed_including_the_option_rows(): void
    {
        $response = $this->from(route('panel.products.create'))
            ->post(route('panel.products.store'), $this->form(['price' => 'abc', 'options' => $this->sizeOptions()]));

        $response->assertRedirect(route('panel.products.create'));

        $this->followRedirects($response)
            ->assertSee('value="Kings Perfume"', false)
            ->assertSee('value="100 ml"', false)
            ->assertSee('options[0][values][1][name_en]', false);
    }

    // ---------------------------------------------------------------- update

    public function test_editing_a_product_keeps_its_address_even_when_it_is_renamed(): void
    {
        $product = Product::factory()->create(['slug' => 'Musk-Failaka', 'name_en' => 'Musk Failaka']);

        $this->put(route('panel.products.update', $product), $this->form([
            'name_en' => 'A Brand New Name', 'name_ar' => 'اسم جديد', 'slug' => 'Musk-Failaka',
        ]))->assertSessionHasNoErrors();

        $product->refresh();

        $this->assertSame('A Brand New Name', $product->name_en);
        $this->assertSame('Musk-Failaka', $product->slug, 'an indexed address keeps its exact spelling');
    }

    public function test_an_address_is_changed_only_when_someone_types_a_different_one(): void
    {
        $product = Product::factory()->create(['slug' => 'old-address']);

        $this->put(route('panel.products.update', $product), $this->form(['slug' => '']));
        $this->assertSame('old-address', $product->fresh()->slug);

        $this->put(route('panel.products.update', $product), $this->form(['slug' => 'New Address']));
        $this->assertSame('new-address', $product->fresh()->slug);
    }

    public function test_saving_an_untouched_form_changes_nothing_not_even_the_ids_of_options_and_tiers(): void
    {
        $product = Product::factory()->priced(10_000)->create();
        $group = OptionGroup::factory()->for($product)->create(['name_en' => 'Size']);
        $value = OptionValue::factory()->for($group, 'group')->priced(2_000)->create(['name_en' => 'Large']);
        $tier = ProductQuantityTier::factory()->for($product)->from(1)->create(['label_en' => '1 Tola']); // label only, as imported
        $paid = ProductQuantityTier::factory()->for($product)->from(3)->fixed(5_000)->create();

        $this->put(route('panel.products.update', $product), $this->form([
            'name_ar' => $product->name_ar, 'name_en' => $product->name_en, 'slug' => $product->slug, 'price' => '10.000',
            'options' => [[
                'id' => $group->id, 'name_ar' => $group->name_ar, 'name_en' => 'Size', 'layout' => 'radio', 'is_required' => '1', 'is_active' => '1',
                'values' => [['id' => $value->id, 'name_ar' => $value->name_ar, 'name_en' => 'Large', 'price' => '2.000', 'is_active' => '1']],
            ]],
            'tiers' => [
                ['id' => $tier->id, 'min_quantity' => '1', 'discount_type' => 'none', 'label_en' => '1 Tola'],
                ['id' => $paid->id, 'min_quantity' => '3', 'discount_type' => 'fixed', 'discount_amount' => '5.000'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([$group->id], $product->optionGroups()->pluck('id')->all());
        $this->assertSame([$value->id], $group->values()->pluck('id')->all());
        $this->assertSame([$tier->id, $paid->id], $product->quantityTiers()->pluck('id')->all());
        $this->assertSame(2_000, $value->fresh()->price_fils);
        $this->assertSame(5_000, $paid->fresh()->discount_fils);
        $this->assertSame('none', $tier->fresh()->discount_type);
    }

    public function test_options_are_matched_by_id_new_ones_added_and_the_ones_left_out_removed(): void
    {
        $product = Product::factory()->create();
        $keep = OptionGroup::factory()->for($product)->create(['name_en' => 'Keep']);
        $drop = OptionGroup::factory()->for($product)->create(['name_en' => 'Drop']);
        $keptValue = OptionValue::factory()->for($keep, 'group')->create(['name_en' => 'Stays']);
        $goneValue = OptionValue::factory()->for($keep, 'group')->create(['name_en' => 'Goes']);

        $this->put(route('panel.products.update', $product), $this->form([
            'options' => [
                ['id' => $keep->id, 'name_ar' => 'ا', 'name_en' => 'Kept Renamed', 'layout' => 'radio', 'is_active' => '1', 'values' => [
                    ['id' => $keptValue->id, 'name_ar' => 'ا', 'name_en' => 'Stays', 'price' => '0', 'is_active' => '1'],
                    ['name_ar' => 'ب', 'name_en' => 'Added', 'price' => '1', 'is_active' => '1'],
                ]],
                ['name_ar' => 'ج', 'name_en' => 'Brand New', 'layout' => 'radio', 'is_active' => '1', 'values' => [['name_ar' => 'د', 'name_en' => 'Only', 'price' => '0', 'is_active' => '1']]],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['Kept Renamed', 'Brand New'], $product->optionGroups()->pluck('name_en')->all());
        $this->assertNull(OptionGroup::query()->find($drop->id));
        $this->assertNull(OptionValue::query()->find($goneValue->id));
        $this->assertSame(['Stays', 'Added'], $keep->values()->pluck('name_en')->all());
        $this->assertSame($keptValue->id, $keep->values()->first()->id);
    }

    public function test_an_id_that_belongs_to_another_product_is_never_touched_from_this_form(): void
    {
        $mine = Product::factory()->create();
        $theirs = Product::factory()->create();
        $theirGroup = OptionGroup::factory()->for($theirs)->create(['name_en' => 'Theirs']);
        $theirValue = OptionValue::factory()->for($theirGroup, 'group')->create(['name_en' => 'Their value']);
        $theirTier = ProductQuantityTier::factory()->for($theirs)->from(2)->create(['label_en' => 'Their tier']);

        $this->put(route('panel.products.update', $mine), $this->form([
            'options' => [['id' => $theirGroup->id, 'name_ar' => 'ا', 'name_en' => 'Hijacked', 'layout' => 'radio', 'is_active' => '1', 'values' => [
                ['id' => $theirValue->id, 'name_ar' => 'ا', 'name_en' => 'Hijacked value', 'price' => '9', 'is_active' => '1'],
            ]]],
            'tiers' => [['id' => $theirTier->id, 'min_quantity' => '5', 'discount_type' => 'none', 'label_en' => 'Hijacked tier']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Theirs', $theirGroup->fresh()->name_en);
        $this->assertSame('Their value', $theirValue->fresh()->name_en);
        $this->assertSame(0, $theirValue->fresh()->price_fils);
        $this->assertSame('Their tier', $theirTier->fresh()->label_en);
        $this->assertSame(2, $theirTier->fresh()->min_quantity);
        $this->assertSame(['Hijacked'], $mine->optionGroups()->pluck('name_en')->all(), 'it becomes a new group of this product instead');
    }

    public function test_leaving_every_option_and_tier_out_removes_them_all(): void
    {
        $product = Product::factory()->create();
        OptionGroup::factory()->for($product)->create();
        ProductQuantityTier::factory()->for($product)->create();

        $this->put(route('panel.products.update', $product), $this->form());

        $this->assertSame(0, $product->optionGroups()->count());
        $this->assertSame(0, $product->quantityTiers()->count());
    }

    public function test_switching_the_discount_off_clears_its_amounts_and_dates(): void
    {
        $product = Product::factory()->percentDiscount(15)->discountWindow('2026-10-01 00:00:00', '2026-10-30 00:00:00')->create();

        $this->put(route('panel.products.update', $product), $this->form(['discount_type' => 'none', 'discount_percent' => '15', 'discount_starts_at' => '2026-10-01T00:00']));

        $product->refresh();
        $this->assertSame(Product::DISCOUNT_NONE, $product->discount_type);
        $this->assertSame('0.00', $product->discount_percent);
        $this->assertSame(0, $product->discount_fils);
        $this->assertNull($product->discount_starts_at);
        $this->assertNull($product->discount_ends_at);
    }

    public function test_related_products_keep_the_order_they_were_ticked_in_and_never_include_the_product_itself(): void
    {
        $product = Product::factory()->create();
        [$a, $b, $c] = Product::factory()->count(3)->create()->all();

        $this->put(route('panel.products.update', $product), $this->form(['related' => [$c->id, $a->id, $b->id]]));
        $this->assertSame([$c->id, $a->id, $b->id], $product->related()->pluck('products.id')->all());

        $this->put(route('panel.products.update', $product), $this->form(['related' => [$product->id]]))->assertSessionHasErrors('related.0');
    }

    public function test_replacing_the_main_picture_removes_the_old_file(): void
    {
        $old = $this->existingUpload('uploads/catalog/old.jpg');
        $product = Product::factory()->create(['main_image' => $old]);

        $this->put(route('panel.products.update', $product), $this->form(['main_image' => $this->jpeg()]));

        $product->refresh();
        $this->assertNotSame($old, $product->main_image);
        $this->assertTrue($this->storedFileExists($product->main_image));
        $this->assertFalse($this->storedFileExists($old));
    }

    public function test_removing_a_picture_removes_its_file_unless_another_product_still_uses_it(): void
    {
        $shared = $this->existingUpload('uploads/catalog/shared.jpg');
        $solo = $this->existingUpload('uploads/catalog/solo.jpg');
        $product = Product::factory()->create(['main_image' => $shared]);
        $sibling = Product::factory()->create(['main_image' => $shared]);
        $image = ProductImage::factory()->for($product)->create(['path' => $solo]);

        $this->put(route('panel.products.update', $product), $this->form(['remove_main_image' => '1', 'remove_gallery' => [$image->id]]));

        $this->assertNull($product->fresh()->main_image);
        $this->assertTrue($this->storedFileExists($shared), 'still the sibling\'s picture');
        $this->assertFalse($this->storedFileExists($solo));
        $this->assertSame($shared, $sibling->fresh()->main_image);
    }

    public function test_a_gallery_picture_belonging_to_another_product_cannot_be_removed_from_here(): void
    {
        $product = Product::factory()->create();
        $theirs = ProductImage::factory()->for(Product::factory())->create(['path' => $this->existingUpload('uploads/catalog/theirs.jpg')]);

        $this->put(route('panel.products.update', $product), $this->form(['remove_gallery' => [$theirs->id]]));

        $this->assertDatabaseHas('product_images', ['id' => $theirs->id]);
        $this->assertTrue($this->storedFileExists('uploads/catalog/theirs.jpg'));
    }

    public function test_a_product_that_does_not_exist_is_a_404(): void
    {
        $this->get(route('panel.products.edit', 999))->assertNotFound();
        $this->put(route('panel.products.update', 999), $this->form())->assertNotFound();
        $this->delete(route('panel.products.destroy', 999))->assertNotFound();
    }

    // --------------------------------------------------------------- actions

    public function test_a_product_can_be_switched_off_and_on_from_the_list(): void
    {
        $product = Product::factory()->create();

        $this->post(route('panel.products.toggle', $product))->assertSessionHas('status');
        $this->assertFalse($product->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.deactivated']);

        $this->post(route('panel.products.toggle', $product))->assertSessionHas('status');
        $this->assertTrue($product->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.activated']);
    }

    public function test_a_hidden_product_disappears_from_the_storefront(): void
    {
        $product = Product::factory()->create(['slug' => 'visible-one']);
        $this->get('/en-KW/products/visible-one')->assertOk();

        $this->post(route('panel.products.toggle', $product));
        $this->startNewRequest();

        $this->get('/en-KW/products/visible-one')->assertNotFound();
    }

    public function test_a_duplicate_starts_a_similar_product_switched_off_with_a_new_address(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->priced(9_000)->create(['name_en' => 'Original', 'slug' => 'original', 'sales_count' => 40, 'overzaki_id' => 'ovz-1']);
        $product->categories()->attach($category);
        $group = OptionGroup::factory()->for($product)->create();
        OptionValue::factory()->for($group, 'group')->priced(1_000)->create(['name_en' => 'Large']);
        ProductQuantityTier::factory()->for($product)->from(2)->fixed(500)->create();
        ProductImage::factory()->for($product)->create(['path' => 'uploads/catalog/shared.jpg']);

        $this->post(route('panel.products.duplicate', $product))->assertSessionHas('status');

        $copy = Product::query()->where('id', '!=', $product->id)->firstOrFail();

        $this->assertFalse($copy->is_active);
        $this->assertSame('original-copy', $copy->slug);
        $this->assertSame('Original (copy)', $copy->name_en);
        $this->assertSame(9_000, $copy->sell_price_fils);
        $this->assertSame(0, $copy->sales_count);
        $this->assertNull($copy->overzaki_id);
        $this->assertSame([$category->id], $copy->categories->modelKeys());
        $this->assertSame(['Large'], $copy->optionGroups()->firstOrFail()->values->pluck('name_en')->all());
        $this->assertNotSame($group->id, $copy->optionGroups()->firstOrFail()->id);
        $this->assertSame(500, $copy->quantityTiers()->firstOrFail()->discount_fils);
        $this->assertSame(['uploads/catalog/shared.jpg'], $copy->images->pluck('path')->all());
        // The original is untouched.
        $this->assertSame(1, $product->optionGroups()->count());
        $this->assertSame('ovz-1', $product->fresh()->overzaki_id);
    }

    public function test_deleting_a_product_removes_it_and_its_parts_but_orders_keep_what_they_bought(): void
    {
        $file = $this->existingUpload('uploads/catalog/doomed.jpg');
        $product = Product::factory()->create(['name_en' => 'Doomed', 'main_image' => $file]);
        $group = OptionGroup::factory()->for($product)->create();
        OptionValue::factory()->for($group, 'group')->create();
        ProductQuantityTier::factory()->for($product)->create();
        WishlistItem::factory()->create(['product_id' => $product->id]);
        $order = Order::factory()->create();
        $item = OrderItem::factory()->for($order)->create(['product_id' => $product->id, 'name_en' => 'Doomed', 'quantity' => 2]);

        $this->delete(route('panel.products.destroy', $product))->assertRedirect(route('panel.products.index'));

        $this->assertNull(Product::query()->find($product->id));
        $this->assertSame(0, OptionGroup::query()->count());
        $this->assertSame(0, OptionValue::query()->count());
        $this->assertSame(0, ProductQuantityTier::query()->count());
        $this->assertSame(0, WishlistItem::query()->count());
        $this->assertFalse($this->storedFileExists($file));

        $item->refresh();
        $this->assertNull($item->product_id);
        $this->assertSame('Doomed', $item->name_en);
        $this->assertSame(2, $item->quantity);
        $this->assertDatabaseHas('activity_logs', ['action' => 'product.deleted']);
    }

    public function test_the_edit_page_shows_the_product_as_it_is_with_money_to_three_decimals(): void
    {
        $product = Product::factory()->priced(12_500)->fixedDiscount(2_500)->create(['name_en' => 'Show Me']);
        $group = OptionGroup::factory()->for($product)->create(['name_en' => 'Size']);
        OptionValue::factory()->for($group, 'group')->priced(5_500)->create(['name_en' => 'Large']);
        ProductQuantityTier::factory()->for($product)->from(3)->fixed(1_000)->create();

        $this->get(route('panel.products.edit', $product))
            ->assertOk()
            ->assertSee('value="Show Me"', false)
            ->assertSee('value="12.500"', false)
            ->assertSee('value="2.500"', false)
            ->assertSee('value="5.500"', false)
            ->assertSee('value="1.000"', false)
            ->assertSee('options[0][values][0][name_en]', false)
            ->assertSee('tiers[0][min_quantity]', false);
    }

    public function test_the_forms_blank_option_and_tier_templates_are_present_for_the_add_buttons(): void
    {
        $this->get(route('panel.products.create'))
            ->assertOk()
            ->assertSee('data-repeat-template="group"', false)
            ->assertSee('data-repeat-template="value"', false)
            ->assertSee('data-repeat-template="tier"', false)
            ->assertSee('options[__G__][values][__V__][name_ar]', false)
            ->assertSee('tiers[__T__][min_quantity]', false);
    }
}
