<?php

namespace Tests\Feature\Services\Store;

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Store\LocalCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LocalCatalogTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function catalog(): LocalCatalog
    {
        return $this->app->make(LocalCatalog::class);
    }

    public function test_lists_only_active_products_best_sellers_first(): void
    {
        Product::factory()->create(['slug' => 'slow', 'sales_count' => 1, 'sort_order' => 1]);
        Product::factory()->create(['slug' => 'popular', 'sales_count' => 9, 'sort_order' => 2]);
        Product::factory()->inactive()->create(['slug' => 'hidden', 'sales_count' => 99]);

        $slugs = array_map(fn ($product) => $product->slug(), $this->catalog()->all());

        $this->assertSame(['popular', 'slow'], $slugs);
    }

    public function test_a_live_fixed_discount_prices_the_product_at_the_engine_price(): void
    {
        Product::factory()->priced(35_000)->fixedDiscount(16_000)->create(['slug' => 'albustan']);

        $product = $this->catalog()->find('albustan');

        $this->assertSame(35.0, $product->listPrice());
        $this->assertSame(19.0, $product->price());
        $this->assertTrue($product->hasDiscount());
        $this->assertSame(46, $product->discountPercent());
    }

    public function test_a_percentage_discount_lands_on_the_exact_fils(): void
    {
        Product::factory()->priced(20_000)->percentDiscount(12.5)->create(['slug' => 'pct']);

        $product = $this->catalog()->find('pct');

        $this->assertSame(17.5, $product->price());
        $this->assertSame(2.5, $product->savings());
    }

    public function test_a_discount_outside_its_window_is_not_shown(): void
    {
        $this->travelTo('2026-11-01 00:00:00');
        Product::factory()->priced(35_000)->fixedDiscount(16_000)
            ->discountWindow('2026-10-01 00:00:00', '2026-10-19 00:00:00')
            ->create(['slug' => 'expired']);

        $product = $this->catalog()->find('expired');

        $this->assertFalse($product->hasDiscount());
        $this->assertSame(35.0, $product->price());
    }

    public function test_find_returns_nothing_for_an_inactive_or_unknown_product(): void
    {
        Product::factory()->inactive()->create(['slug' => 'hidden']);

        $this->assertNull($this->catalog()->find('hidden'));
        $this->assertNull($this->catalog()->find('never-existed'));
    }

    public function test_the_gallery_lists_the_main_image_first_and_resolves_local_paths_to_urls(): void
    {
        $product = Product::factory()->create(['slug' => 'pics', 'main_image' => 'uploads/catalog/products/main.png']);
        ProductImage::factory()->for($product)->create(['path' => 'https://cdn.example.com/second.jpg', 'sort_order' => 0]);
        ProductImage::factory()->for($product)->create(['path' => 'uploads/catalog/products/third.png', 'sort_order' => 1]);

        $gallery = $this->catalog()->find('pics')->gallery();

        $this->assertCount(3, $gallery);
        $this->assertStringEndsWith('/uploads/catalog/products/main.png', $gallery[0]);
        $this->assertSame('https://cdn.example.com/second.jpg', $gallery[1]);
        $this->assertStringEndsWith('/uploads/catalog/products/third.png', $gallery[2]);
    }

    public function test_options_come_through_as_groups_with_dinar_prices_and_packages_are_recognised(): void
    {
        $jar = Product::factory()->priced(0)->create(['slug' => 'jar']);
        $weight = OptionGroup::factory()->for($jar)->create(['name_en' => 'Weight', 'name_ar' => 'الوزن']);
        OptionValue::factory()->for($weight, 'group')->priced(60_000)->create(['name_en' => '2 tola', 'sort_order' => 0]);
        OptionValue::factory()->for($weight, 'group')->priced(145_500)->create(['name_en' => '5 tola', 'sort_order' => 1]);
        $package = Product::factory()->priced(20_000)->create(['slug' => 'package']);
        $pick = OptionGroup::factory()->for($package)->checkbox(3, 3)->create();
        OptionValue::factory()->for($pick, 'group')->priced(5_000)->count(4)->create();

        $jarView = $this->catalog()->find('jar');
        $packageView = $this->catalog()->find('package');

        $this->assertTrue($jarView->hasOptions());
        $this->assertTrue($jarView->isPricedByOptions());
        $this->assertSame([60.0, 145.5], array_column($jarView->options()[0]['values'], 'price'));
        $this->assertSame(60.0, $jarView->startingPrice());
        $this->assertTrue($packageView->isBundle());
        $this->assertFalse($packageView->hasOptions(), 'nobody picks from a package, so it has nothing to choose');
    }

    public function test_an_inactive_option_value_is_left_out(): void
    {
        $product = Product::factory()->create(['slug' => 'p']);
        $group = OptionGroup::factory()->for($product)->create();
        OptionValue::factory()->for($group, 'group')->create(['name_en' => 'Kept']);
        OptionValue::factory()->for($group, 'group')->create(['name_en' => 'Gone', 'is_active' => false]);

        $values = $this->catalog()->find('p')->options()[0]['values'];

        $this->assertSame(['Kept'], array_column($values, 'name'));
    }

    public function test_stock_is_unlimited_unless_tracked_and_runs_out_at_zero(): void
    {
        Product::factory()->create(['slug' => 'untracked']);
        Product::factory()->withStock(0)->create(['slug' => 'sold-out']);

        $this->assertTrue($this->catalog()->find('untracked')->inStock());
        $this->assertFalse($this->catalog()->find('sold-out')->inStock());
    }

    public function test_the_quantity_left_is_only_shown_once_stock_is_low(): void
    {
        Product::factory()->withStock(20, lowAt: 3)->create(['slug' => 'plenty']);
        Product::factory()->withStock(2, lowAt: 3)->create(['slug' => 'few']);

        $plenty = $this->catalog()->find('plenty');
        $few = $this->catalog()->find('few');

        $this->assertNull($plenty->quantityAvailable());
        $this->assertFalse($plenty->isLowStock());
        $this->assertSame(2, $few->quantityAvailable());
        $this->assertTrue($few->isLowStock());
    }

    public function test_the_most_a_shopper_can_order_is_the_lower_of_the_product_limit_and_the_stock(): void
    {
        Product::factory()->withStock(10)->create(['slug' => 'limited', 'max_per_order' => 4]);
        Product::factory()->withStock(2)->create(['slug' => 'scarce', 'max_per_order' => 4]);
        Product::factory()->create(['slug' => 'unbounded']);

        $this->assertSame(4, $this->catalog()->find('limited')->maxPerOrder());
        $this->assertSame(2, $this->catalog()->find('scarce')->maxPerOrder());
        $this->assertNull($this->catalog()->find('unbounded')->maxPerOrder());
    }

    public function test_categories_are_localised_active_only_and_can_be_limited_to_the_top_level(): void
    {
        $parent = Category::factory()->create(['slug' => 'perfumes', 'name_en' => 'Perfumes', 'name_ar' => 'العطور', 'sort_order' => 1]);
        Category::factory()->childOf($parent)->create(['slug' => '100ml', 'name_en' => '100ml', 'name_ar' => '100 مل', 'sort_order' => 2]);
        Category::factory()->inactive()->create(['slug' => 'closed']);

        app()->setLocale('ar');
        $all = $this->catalog()->categories();
        $top = $this->catalog()->categories(topLevelOnly: true);

        $this->assertSame(['العطور', '100 مل'], array_column($all, 'name'));
        $this->assertSame([1, 2], array_column($all, 'level'));
        $this->assertSame((string) $parent->id, $all[1]['parentId']);
        $this->assertSame(['perfumes'], array_column($top, 'slug'));
    }

    public function test_collection_cards_count_live_products_and_borrow_the_best_sellers_photo(): void
    {
        $category = Category::factory()->create(['slug' => 'perfumes', 'image' => null]);
        $empty = Category::factory()->create(['slug' => 'empty']);
        Product::factory()->create(['slug' => 'small', 'sales_count' => 1, 'main_image' => 'uploads/catalog/products/small.png'])->categories()->attach($category);
        Product::factory()->create(['slug' => 'big', 'sales_count' => 9, 'main_image' => 'uploads/catalog/products/big.png'])->categories()->attach($category);
        Product::factory()->inactive()->create(['slug' => 'hidden'])->categories()->attach($category);

        $cards = $this->catalog()->categoriesWithCounts();

        $this->assertCount(1, $cards);
        $this->assertSame('perfumes', $cards[0]['slug']);
        $this->assertSame(2, $cards[0]['count']);
        $this->assertStringEndsWith('/uploads/catalog/products/big.png', $cards[0]['image']);
        $this->assertTrue($cards[0]['imageFromProduct']);
        $this->assertNotContains($empty->slug, array_column($cards, 'slug'));
    }

    public function test_search_ignores_arabic_diacritics_tatweel_and_letter_variants(): void
    {
        Product::factory()->create(['slug' => 'oud', 'name_ar' => 'عود أبيض', 'name_en' => 'White oud']);
        Product::factory()->create(['slug' => 'other', 'name_ar' => 'مسك', 'name_en' => 'Musk']);

        $withTatweel = $this->catalog()->search('عــود');
        $withVariant = $this->catalog()->search('ابيض');
        $english = $this->catalog()->search('WHITE');

        $this->assertSame(['oud'], array_map(fn ($p) => $p->slug(), $withTatweel));
        $this->assertSame(['oud'], array_map(fn ($p) => $p->slug(), $withVariant));
        $this->assertSame(['oud'], array_map(fn ($p) => $p->slug(), $english));
        $this->assertSame([], $this->catalog()->search('   '));
    }

    public function test_search_also_finds_a_product_by_sku_or_category_name(): void
    {
        $category = Category::factory()->create(['name_en' => 'Incense', 'name_ar' => 'بخور']);
        Product::factory()->create(['slug' => 'burner', 'sku' => 'BRN-77'])->categories()->attach($category);

        $this->assertSame(['burner'], array_map(fn ($p) => $p->slug(), $this->catalog()->search('brn-77')));
        $this->assertSame(['burner'], array_map(fn ($p) => $p->slug(), $this->catalog()->search('incense')));
    }

    public function test_the_listing_filters_by_price_availability_and_offers_and_sorts_and_pages(): void
    {
        Product::factory()->priced(10_000)->create(['slug' => 'cheap', 'name_en' => 'Cheap']);
        Product::factory()->priced(30_000)->fixedDiscount(5_000)->create(['slug' => 'offer', 'name_en' => 'Offer']);
        Product::factory()->priced(50_000)->withStock(0)->create(['slug' => 'sold-out', 'name_en' => 'Sold out']);
        $slugs = fn ($page) => array_map(fn ($p) => $p->slug(), $page->items());

        $byPrice = $this->catalog()->filterable(['min' => 20, 'max' => 40]);
        $inStock = $this->catalog()->filterable(['availability' => 'in', 'sort' => 'price-desc']);
        $offers = $this->catalog()->filterable(['offers' => true]);
        $paged = $this->catalog()->filterable(['sort' => 'price-asc'], perPage: 2, page: 2);

        $this->assertSame(['offer'], $slugs($byPrice));
        $this->assertSame(['offer', 'cheap'], $slugs($inStock));
        $this->assertSame(['offer'], $slugs($offers));
        $this->assertSame(['sold-out'], $slugs($paged));
        $this->assertSame(3, $paged->total());
    }

    public function test_the_listing_can_be_limited_to_a_category_by_slug(): void
    {
        $category = Category::factory()->create(['slug' => 'musk']);
        Product::factory()->create(['slug' => 'in-musk'])->categories()->attach($category);
        Product::factory()->create(['slug' => 'elsewhere']);

        $page = $this->catalog()->filterable(['category' => 'musk']);

        $this->assertSame(['in-musk'], array_map(fn ($p) => $p->slug(), $page->items()));
    }

    public function test_the_price_filter_range_comes_from_live_prices_and_ignores_free_items(): void
    {
        Product::factory()->priced(12_400)->create();
        Product::factory()->priced(49_100)->create();
        Product::factory()->priced(0)->create();

        $this->assertSame(['min' => 12, 'max' => 50], $this->catalog()->priceRange());
    }

    public function test_related_products_follow_the_curated_order_then_fall_back_to_the_same_category(): void
    {
        $category = Category::factory()->create();
        $main = Product::factory()->create(['slug' => 'main']);
        $first = Product::factory()->create(['slug' => 'first']);
        $second = Product::factory()->create(['slug' => 'second']);
        $sibling = Product::factory()->create(['slug' => 'sibling']);
        $lonely = Product::factory()->create(['slug' => 'lonely']);
        $main->related()->attach([$second->id => ['sort_order' => 0], $first->id => ['sort_order' => 1]]);
        $lonely->categories()->attach($category);
        $sibling->categories()->attach($category);

        $curated = $this->catalog()->related((string) $main->id);
        $fallback = $this->catalog()->related((string) $lonely->id);

        $this->assertSame(['second', 'first'], array_map(fn ($p) => $p->slug(), $curated));
        $this->assertSame(['sibling'], array_map(fn ($p) => $p->slug(), $fallback));
        $this->assertSame([], $this->catalog()->related('999999'));
    }

    public function test_new_arrivals_are_the_most_recently_created(): void
    {
        $this->travelTo('2026-01-01 00:00:00');
        Product::factory()->create(['slug' => 'older']);
        $this->travelTo('2026-06-01 00:00:00');
        Product::factory()->create(['slug' => 'newer']);

        $slugs = array_map(fn ($p) => $p->slug(), $this->catalog()->newArrivals(1));

        $this->assertSame(['newer'], $slugs);
    }
}
