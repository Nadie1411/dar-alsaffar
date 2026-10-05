<?php

namespace Tests\Feature\Services\Store\Import;

use App\Models\Category;
use App\Models\DeliveryArea;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ServiceAddon;
use App\Services\Store\Import\ImageMirror;
use App\Services\Store\Import\ImportReport;
use App\Services\Store\Import\OverzakiImporter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\FakesOverzaki;
use Tests\TestCase;

class OverzakiImporterTest extends TestCase
{
    use FakesOverzaki, LazilyRefreshDatabase;

    private string $imageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imageDirectory = sys_get_temp_dir().'/store-import-test-'.bin2hex(random_bytes(4));
        $this->app->instance(ImageMirror::class, new ImageMirror($this->imageDirectory, 'uploads/catalog'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->imageDirectory);

        parent::tearDown();
    }

    private function import(array $options = []): ImportReport
    {
        return $this->app->make(OverzakiImporter::class)->run($options);
    }

    public function test_imports_a_product_with_its_price_discount_stock_and_images(): void
    {
        $this->fakeOverzaki();

        $this->import();

        $product = Product::query()->where('overzaki_id', 'p-albustan')->firstOrFail();
        $this->assertSame('ALBUSTAN', $product->slug);
        $this->assertSame(35_000, $product->sell_price_fils);
        $this->assertSame('fixed', $product->discount_type);
        $this->assertSame(16_000, $product->discount_fils);
        $this->assertSame(['5 نجوم'], $product->tags);
        $this->assertMatchesRegularExpression('#^uploads/catalog/products/[0-9a-f]{24}\.png$#', $product->main_image);
        $this->assertFileExists($this->imageDirectory.'/products/'.basename($product->main_image));
        // The gallery listed the main image again; it must not be stored twice.
        $this->assertCount(1, $product->images);
        $this->assertDatabaseCount('products', 3);
    }

    public function test_a_second_run_updates_in_place_and_reuses_the_images_it_already_has(): void
    {
        $this->fakeOverzaki();
        $this->import();

        $second = $this->import();

        $this->assertDatabaseCount('products', 3);
        $this->assertDatabaseCount('categories', 3);
        $this->assertSame(['created' => 0, 'updated' => 3], $second->counts['products']);
        $this->assertSame(0, $second->images['downloaded']);
        $this->assertGreaterThan(0, $second->images['reused']);
    }

    public function test_links_sub_categories_to_their_parent_and_attaches_the_whole_chain_to_the_product(): void
    {
        $this->fakeOverzaki();

        $this->import();

        $parent = Category::query()->where('overzaki_id', 'c-perfumes')->firstOrFail();
        $child = Category::query()->where('overzaki_id', 'c-100ml')->firstOrFail();
        $product = Product::query()->where('overzaki_id', 'p-albustan')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertFalse($child->is_active);
        $this->assertEqualsCanonicalizing([$parent->id, $child->id], $product->categories->modelKeys());
        $this->assertSame('العطور', $parent->name_ar);
    }

    public function test_imports_option_groups_and_values_and_leaves_out_deleted_ones(): void
    {
        $this->fakeOverzaki();

        $this->import();

        $jar = Product::query()->where('overzaki_id', 'p-jar')->firstOrFail();
        $weight = $jar->optionGroups()->with('values')->firstOrFail();
        $this->assertSame(['radio', true], [$weight->layout, $weight->is_required]);
        $this->assertSame([60_000, 145_500], $weight->values->pluck('price_fils')->all());
        $this->assertMatchesRegularExpression('#^uploads/catalog/options/#', $weight->values[1]->image);

        $package = Product::query()->where('overzaki_id', 'p-package')->firstOrFail();
        $pick = $package->optionGroups()->with('values')->get();
        $this->assertCount(1, $pick);
        $this->assertSame(['checkbox', 3, 3], [$pick[0]->layout, $pick[0]->min_choices, $pick[0]->max_choices]);
        $this->assertSame(['Musk', 'Amber'], $pick[0]->values->pluck('name_en')->all());
    }

    public function test_products_that_share_an_option_group_in_overzaki_each_keep_their_own_copy(): void
    {
        // Overzaki reuses one group document across products; importing the
        // second must not take the group away from the first.
        $this->fakeOverzaki([
            self::API.'/products/get_products_customer*' => Http::response($this->fixture('overzaki/products-list-with-twin')),
            self::API.'/products/v2/slug/indian-jar-twin' => Http::response($this->fixture('overzaki/product-jar-twin')),
        ]);

        $this->import();
        $this->import();

        foreach (['p-jar', 'p-jar-twin'] as $overzakiId) {
            $groups = Product::query()->where('overzaki_id', $overzakiId)->firstOrFail()->optionGroups()->with('values')->get();
            $this->assertCount(1, $groups, $overzakiId);
            $this->assertSame([60_000, 145_500], $groups[0]->values->pluck('price_fils')->all(), $overzakiId);
        }
        $this->assertSame(3, OptionGroup::query()->count());
    }

    public function test_removes_options_and_values_that_overzaki_no_longer_has(): void
    {
        $withoutLastValue = $this->fixture('overzaki/product-jar');
        array_pop($withoutLastValue['data']['product']['options'][0]['values']);
        // The first run sees the full jar, the second the jar with a value gone.
        $this->fakeOverzaki([
            self::API.'/products/v2/slug/indian-jar' => Http::sequence()
                ->push($this->fixture('overzaki/product-jar'))
                ->push($withoutLastValue),
        ]);

        $this->import();
        $this->import();

        $jar = Product::query()->where('overzaki_id', 'p-jar')->firstOrFail();
        $this->assertSame(['2 tola'], $jar->optionGroups()->firstOrFail()->values->pluck('name_en')->all());
    }

    public function test_links_related_products_and_ignores_ones_that_are_not_in_the_feed(): void
    {
        $this->fakeOverzaki();

        $this->import();

        $albustan = Product::query()->where('overzaki_id', 'p-albustan')->firstOrFail();
        $this->assertSame(['indian-jar'], $albustan->related->pluck('slug')->all());
    }

    public function test_reads_each_areas_delivery_fee_by_pricing_a_plain_product_for_it(): void
    {
        $this->fakeOverzaki();

        $report = $this->import();

        $this->assertSame(2_000, DeliveryArea::query()->where('overzaki_id', 'area-salmiya')->value('fee_fils'));
        $this->assertFalse(DeliveryArea::query()->where('overzaki_id', 'area-closed')->value('is_active'));
        // The cheapest product with a price of its own and no required choices.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'carts/checker_customer')
            && $request['items'][0]['productId'] === 'p-albustan'
            && $request['address'] === ['type' => 'home', 'country' => config('overzaki.country_id'), 'city' => 'city-hawalli', 'area' => 'area-salmiya']);
        $this->assertSame([
            'minimum_order_fils' => 5_000,
            'free_shipping_threshold_fils' => 30_000,
            'cod_fee_fils' => 500,
            'vat_on_probe_fils' => 0,
            'cash_on_delivery_available' => true,
        ], $report->observed);
    }

    public function test_a_failed_fee_lookup_leaves_the_fee_empty_and_warns(): void
    {
        $this->fakeOverzaki([self::API.'/carts/checker_customer/' => Http::response(['message' => 'down'], 500)]);

        $report = $this->import();

        $this->assertNull(DeliveryArea::query()->where('overzaki_id', 'area-salmiya')->value('fee_fils'));
        $this->assertContains("The delivery fee could not be read for 2 area(s); they will use the store's default fee.", $report->warnings);
    }

    public function test_skipping_fees_never_calls_the_cart_checker(): void
    {
        $this->fakeOverzaki();

        $this->import(['fees' => false]);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'checker_customer'));
        $this->assertNull(DeliveryArea::query()->where('overzaki_id', 'area-salmiya')->value('fee_fils'));
    }

    public function test_skipping_images_keeps_the_remote_urls_and_downloads_nothing(): void
    {
        $this->fakeOverzaki();

        $this->import(['images' => false]);

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'cdn.example.com'));
        $this->assertSame(
            'https://cdn.example.com/img/albustan.jpg',
            Product::query()->where('overzaki_id', 'p-albustan')->value('main_image')
        );
    }

    public function test_a_failed_image_download_keeps_the_remote_url_and_is_counted(): void
    {
        $this->fakeOverzaki(['cdn.example.com/*' => Http::response('nope', 404)]);

        $report = $this->import();

        $this->assertSame(
            'https://cdn.example.com/img/albustan.jpg',
            Product::query()->where('overzaki_id', 'p-albustan')->value('main_image')
        );
        $this->assertGreaterThan(0, $report->images['failed']);
        $this->assertSame(0, $report->images['downloaded']);
    }

    public function test_a_download_that_is_not_an_image_is_refused(): void
    {
        $this->fakeOverzaki(['cdn.example.com/*' => Http::response('<svg onload="x()"/>', 200, ['Content-Type' => 'image/svg+xml'])]);

        $this->import();

        $this->assertSame(
            'https://cdn.example.com/img/albustan.jpg',
            Product::query()->where('overzaki_id', 'p-albustan')->value('main_image')
        );
        $this->assertDirectoryDoesNotExist($this->imageDirectory);
    }

    public function test_an_image_served_with_a_generic_content_type_is_still_stored_by_what_it_really_is(): void
    {
        // Overzaki's CDN labels every file application/octet-stream.
        $this->fakeOverzaki(['cdn.example.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'application/octet-stream'])]);

        $report = $this->import();

        $this->assertMatchesRegularExpression(
            '#^uploads/catalog/products/[0-9a-f]{24}\.png$#',
            Product::query()->where('overzaki_id', 'p-albustan')->value('main_image')
        );
        $this->assertSame(0, $report->images['failed']);
    }

    public function test_a_file_that_claims_to_be_an_image_but_is_not_is_refused(): void
    {
        $this->fakeOverzaki(['cdn.example.com/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'image/png'])]);

        $report = $this->import();

        $this->assertSame(
            'https://cdn.example.com/img/albustan.jpg',
            Product::query()->where('overzaki_id', 'p-albustan')->value('main_image')
        );
        $this->assertSame(0, $report->images['downloaded']);
    }

    public function test_imports_quantity_tiers_and_drops_them_when_overzaki_switches_the_packages_off(): void
    {
        $withoutPackages = $this->fixture('overzaki/product-albustan');
        $withoutPackages['data']['product']['oneTimeDiscount']['enabled'] = false;
        $this->fakeOverzaki([
            self::API.'/products/v2/slug/ALBUSTAN' => Http::sequence()
                ->push($this->fixture('overzaki/product-albustan'))
                ->push($withoutPackages),
        ]);

        $this->import();
        $tiers = Product::query()->where('overzaki_id', 'p-albustan')->firstOrFail()->quantityTiers;
        $this->assertSame([1, 2, 3], $tiers->pluck('min_quantity')->all());
        $this->assertSame([0, 5_000, 0], $tiers->pluck('discount_fils')->all());
        $this->assertSame([false, false, true], $tiers->pluck('free_delivery')->all());

        $this->import();
        $this->assertSame(0, Product::query()->where('overzaki_id', 'p-albustan')->firstOrFail()->quantityTiers()->count());
    }

    public function test_imports_service_add_ons(): void
    {
        $this->fakeOverzaki();

        $this->import();

        $addon = ServiceAddon::query()->where('overzaki_id', 'addon-wrap')->firstOrFail();
        $this->assertSame([1_500, 'Gift wrapping', 'تغليف يدوي'], [$addon->price_fils, $addon->name_en, $addon->description_ar]);
    }

    public function test_an_empty_product_feed_changes_nothing_and_stops(): void
    {
        $existing = Product::factory()->create(['overzaki_id' => 'p-existing']);
        $this->fakeOverzaki([self::API.'/products/get_products_customer*' => Http::response(['data' => ['data' => [], 'count' => 0]])]);

        try {
            $this->import(['deactivate_missing' => true]);
            $this->fail('An empty feed should have stopped the import.');
        } catch (RuntimeException $e) {
            $this->assertSame('Overzaki returned no products, so nothing was changed.', $e->getMessage());
        }

        $this->assertTrue($existing->fresh()->is_active);
    }

    public function test_hides_products_missing_from_the_feed_only_when_asked_and_never_touches_locally_made_ones(): void
    {
        $gone = Product::factory()->create(['overzaki_id' => 'p-gone']);
        $local = Product::factory()->create(['overzaki_id' => null]);
        $this->fakeOverzaki();

        $this->import();
        $this->assertTrue($gone->fresh()->is_active);

        $report = $this->import(['deactivate_missing' => true]);

        $this->assertFalse($gone->fresh()->is_active);
        $this->assertTrue($local->fresh()->is_active);
        $this->assertSame(1, $report->deactivated);
    }

    public function test_a_slug_already_held_by_another_product_is_renamed_with_a_warning(): void
    {
        Product::factory()->create(['slug' => 'ALBUSTAN', 'overzaki_id' => null]);
        $this->fakeOverzaki();

        $report = $this->import();

        $this->assertSame('ALBUSTAN-bustan', Product::query()->where('overzaki_id', 'p-albustan')->value('slug'));
        $this->assertContains('Two items share the slug "ALBUSTAN"; one was renamed.', $report->warnings);
    }

    public function test_option_groups_are_counted_in_the_report(): void
    {
        $this->fakeOverzaki();

        $report = $this->import();

        $this->assertSame(['created' => 2, 'updated' => 0], $report->counts['option_groups']);
        $this->assertSame(2, OptionGroup::query()->count());
    }
}
