<?php

namespace App\Services\Store\Import;

use App\Models\Category;
use App\Models\DeliveryArea;
use App\Models\DeliveryCity;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ServiceAddon;
use App\Services\Overzaki\OverzakiClient;
use App\Support\Money;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Copies the store out of Overzaki and into this application's own tables.
 *
 * Safe to run repeatedly: every row is matched on the id it had in Overzaki
 * and updated in place, so until the switch-over — while Overzaki is still
 * where the shop is managed — a re-run simply brings this copy up to date.
 *
 * All network work (reads and image downloads) happens before the database
 * transaction that stores it, so an import never holds the SQLite file locked
 * while it waits on a slow download.
 */
class OverzakiImporter
{
    protected const PAGE_SIZE = 250;

    protected const DETAIL_BATCH = 20;

    protected const FEE_BATCH = 15;

    protected ImportReport $report;

    /** @var array<string,int> Overzaki category id => local id */
    protected array $categoryIds = [];

    /** @var array<string,?string> Overzaki category id => Overzaki parent id */
    protected array $categoryParents = [];

    protected bool $mirrorImages = true;

    protected ?Closure $progress = null;

    public function __construct(
        protected OverzakiClient $client,
        protected OverzakiMapper $mapper,
        protected ImageMirror $images,
    ) {}

    /** @param  Closure(string):void  $callback  told what stage the import has reached */
    public function reportProgressTo(Closure $callback): static
    {
        $this->progress = $callback;

        return $this;
    }

    /**
     * @param  array{images?:bool,fees?:bool,deactivate_missing?:bool}  $options
     */
    public function run(array $options = []): ImportReport
    {
        $this->report = new ImportReport;
        $this->categoryIds = [];
        $this->categoryParents = [];
        $this->mirrorImages = $options['images'] ?? true;
        $this->images->resetStats();

        $this->say('Reading the product list');
        $rows = $this->fetchProducts();

        $this->say('Importing categories');
        $this->importTopLevelCategories();

        $this->say('Importing '.count($rows).' products');
        $productIds = $this->importProducts($rows);

        $this->linkCategoryParents();

        $this->say('Importing delivery areas');
        $this->importDelivery($options['fees'] ?? true);

        $this->say('Importing add-ons');
        $this->importAddons();

        if ($options['deactivate_missing'] ?? false) {
            $this->report->deactivated = Product::query()
                ->whereNotNull('overzaki_id')
                ->whereNotIn('overzaki_id', array_keys($productIds))
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $this->report->images = $this->images->stats();

        return $this->report;
    }

    // -------------------------------------------------------------- products

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function fetchProducts(): array
    {
        $rows = [];

        for ($page = 1; $page <= 20; $page++) {
            $response = $this->client->post(
                config('overzaki.endpoints.products'),
                [],
                ['pageSize' => self::PAGE_SIZE, 'pageNumber' => $page, 'sort' => '-totalOrders']
            );

            $batch = $response['data'] ?? [];
            $rows = array_merge($rows, array_values(array_filter($batch, fn ($row) => is_array($row) && ! empty($row['_id']))));

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }
        }

        // The client degrades a failed call to an empty list. Treating that as
        // "the shop has no products" would wipe the copy on the next prune.
        if ($rows === []) {
            throw new RuntimeException('Overzaki returned no products, so nothing was changed.');
        }

        return $rows;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,int> Overzaki id => local id
     */
    protected function importProducts(array $rows): array
    {
        $paths = [];

        foreach ($rows as $row) {
            if (! empty($row['slug'])) {
                $paths[$row['_id']] = config('overzaki.endpoints.product').rawurlencode($row['slug']);
            }
        }

        // The list omits option groups, descriptions' full text and more, so
        // each product's detail record is the one that gets imported.
        $details = [];

        foreach (array_chunk($paths, self::DETAIL_BATCH, true) as $batch) {
            $details += $this->client->pool($batch);
        }

        $imported = [];
        $related = [];

        foreach ($rows as $row) {
            $raw = $details[$row['_id']]['product'] ?? null;

            if (! is_array($raw)) {
                $this->report->warn('No detail record for "'.($row['slug'] ?? $row['_id']).'"; its list entry was used, so options may be missing.');
                $raw = $row;
            }

            $localId = $this->importProduct($raw);
            $imported[$raw['_id']] = $localId;
            $related[$localId] = $this->mapper->relatedIds($raw);
        }

        $this->linkRelatedProducts($related, $imported);

        return $imported;
    }

    /**
     * @param  array<string,mixed>  $raw
     */
    protected function importProduct(array $raw): int
    {
        $attributes = $this->mapper->product($raw);
        $attributes['main_image'] = $this->mirror($attributes['main_image'], 'products');
        $gallery = array_map(fn (string $url) => $this->mirror($url, 'products'), $this->mapper->gallery($raw));

        $categories = [];

        foreach ($raw['categories'] ?? [] as $categoryRaw) {
            if (is_array($categoryRaw) && ! empty($categoryRaw['_id'])) {
                $categories[] = $this->prepareCategory($categoryRaw);
            }
        }

        $groups = $this->prepareOptionGroups($raw['options'] ?? []);
        $tiers = $this->mapper->quantityTiers($raw);

        return DB::transaction(function () use ($attributes, $gallery, $categories, $groups, $tiers): int {
            $product = Product::firstOrNew(['overzaki_id' => $attributes['overzaki_id']]);
            $attributes['slug'] = $this->freeSlug(Product::class, $attributes['slug'], $product);
            $product->fill($attributes)->save();
            $this->report->record('products', $product);

            $product->images()->delete();

            foreach ($gallery as $position => $path) {
                $product->images()->create(['path' => $path, 'sort_order' => $position]);
            }

            $product->categories()->sync(array_map(
                fn (array $category) => $this->saveCategory($category['raw'], $category['attributes'])->id,
                $categories
            ));

            $this->syncOptions($product, $groups);

            $product->quantityTiers()->delete();

            foreach ($tiers as $tier) {
                $product->quantityTiers()->create($tier);
            }

            return $product->id;
        });
    }

    /**
     * @param  array<string,int>  $imported  Overzaki id => local id
     * @param  array<int,array<int,string>>  $related  local id => Overzaki ids of related products
     */
    protected function linkRelatedProducts(array $related, array $imported): void
    {
        foreach ($related as $localId => $overzakiIds) {
            $sync = [];

            foreach ($overzakiIds as $position => $overzakiId) {
                if (isset($imported[$overzakiId]) && $imported[$overzakiId] !== $localId) {
                    $sync[$imported[$overzakiId]] = ['sort_order' => $position];
                }
            }

            Product::query()->find($localId)?->related()->sync($sync);
        }
    }

    // ------------------------------------------------------------ categories

    protected function importTopLevelCategories(): void
    {
        $response = $this->client->get(config('overzaki.endpoints.categories'));

        foreach ($response['data'] ?? [] as $raw) {
            if (is_array($raw) && ! empty($raw['_id'])) {
                $prepared = $this->prepareCategory($raw);
                $this->saveCategory($prepared['raw'], $prepared['attributes']);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array{raw:array<string,mixed>,attributes:array<string,mixed>}
     */
    protected function prepareCategory(array $raw): array
    {
        $attributes = $this->mapper->category($raw);
        $attributes['image'] = $this->mirror($attributes['image'], 'categories');
        $attributes['icon'] = $this->mirror($attributes['icon'], 'categories');

        return ['raw' => $raw, 'attributes' => $attributes];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  array<string,mixed>  $attributes
     */
    protected function saveCategory(array $raw, array $attributes): Category
    {
        $category = Category::firstOrNew(['overzaki_id' => $attributes['overzaki_id']]);
        $attributes['slug'] = $this->freeSlug(Category::class, $attributes['slug'], $category);
        $category->fill($attributes)->save();
        $this->report->record('categories', $category);

        $this->categoryIds[$attributes['overzaki_id']] = $category->id;
        $this->categoryParents[$attributes['overzaki_id']] = $raw['parentId'] ?? null;

        return $category;
    }

    /** Parents are wired up last, once every category in every product's chain exists. */
    protected function linkCategoryParents(): void
    {
        foreach ($this->categoryParents as $overzakiId => $parentOverzakiId) {
            $parentId = $parentOverzakiId === null ? null : ($this->categoryIds[$parentOverzakiId] ?? null);

            if ($parentOverzakiId !== null && $parentId === null) {
                $this->report->warn('A sub-category\'s parent was not in the feed, so it was imported as a top-level category.');
            }

            Category::query()->whereKey($this->categoryIds[$overzakiId])->update(['parent_id' => $parentId]);
        }
    }

    // --------------------------------------------------------------- options

    /**
     * @param  array<int,mixed>  $rawGroups
     * @return array<int,array{attributes:array<string,mixed>,values:array<int,array<string,mixed>>}>
     */
    protected function prepareOptionGroups(array $rawGroups): array
    {
        $prepared = [];

        foreach ($rawGroups as $groupRaw) {
            if (! is_array($groupRaw) || empty($groupRaw['_id']) || ($groupRaw['isDelete'] ?? false)) {
                continue;
            }

            $values = [];

            foreach ($groupRaw['values'] ?? [] as $valueRaw) {
                if (! is_array($valueRaw) || empty($valueRaw['_id']) || ($valueRaw['isDelete'] ?? false)) {
                    continue;
                }

                $attributes = $this->mapper->optionValue($valueRaw);
                $attributes['image'] = $this->mirror($attributes['image'], 'options');
                $values[] = $attributes;

                if ((float) ($valueRaw['discountValue'] ?? 0) > 0) {
                    $this->report->warn('Some option values carry their own discount; Overzaki does not apply it at checkout, so it was not carried over.');
                }
            }

            $prepared[] = ['attributes' => $this->mapper->optionGroup($groupRaw), 'values' => $values];
        }

        return $prepared;
    }

    /**
     * Mirrors the product's option groups, dropping any that Overzaki no
     * longer has so a removed choice cannot linger on the product page.
     *
     * @param  array<int,array{attributes:array<string,mixed>,values:array<int,array<string,mixed>>}>  $groups
     */
    protected function syncOptions(Product $product, array $groups): void
    {
        $keptGroups = [];

        foreach ($groups as $prepared) {
            $group = OptionGroup::firstOrNew(['product_id' => $product->id, 'overzaki_id' => $prepared['attributes']['overzaki_id']]);
            $group->fill($prepared['attributes'] + ['product_id' => $product->id])->save();
            $this->report->record('option_groups', $group);
            $keptGroups[] = $group->id;

            $keptValues = [];

            foreach ($prepared['values'] as $attributes) {
                $value = OptionValue::firstOrNew(['option_group_id' => $group->id, 'overzaki_id' => $attributes['overzaki_id']]);
                $value->fill($attributes + ['option_group_id' => $group->id])->save();
                $this->report->record('option_values', $value);
                $keptValues[] = $value->id;
            }

            $group->values()->whereNotIn('id', $keptValues)->delete();
        }

        $product->optionGroups()->whereNotIn('id', $keptGroups)->delete();
    }

    // -------------------------------------------------------------- delivery

    protected function importDelivery(bool $probeFees): void
    {
        $response = $this->client->get(
            config('overzaki.endpoints.citiesWithAreas').config('overzaki.country_id')
        );

        $cities = $response['cities'] ?? [];

        if (! is_array($cities) || $cities === []) {
            $this->report->warn('Overzaki returned no delivery areas, so the existing ones were left alone.');

            return;
        }

        /** @var array<int,array{0:string,1:string}> $areas local area id => [Overzaki city id, Overzaki area id] */
        $areas = [];

        DB::transaction(function () use ($cities, &$areas): void {
            foreach (array_values($cities) as $position => $cityRaw) {
                if (! is_array($cityRaw) || empty($cityRaw['cityId'])) {
                    continue;
                }

                $city = DeliveryCity::firstOrNew(['overzaki_id' => (string) $cityRaw['cityId']]);
                $city->fill($this->mapper->city($cityRaw, $position))->save();
                $this->report->record('delivery_cities', $city);

                foreach (array_values($cityRaw['areas'] ?? []) as $areaPosition => $areaRaw) {
                    if (! is_array($areaRaw) || empty($areaRaw['_id'])) {
                        continue;
                    }

                    $area = DeliveryArea::firstOrNew(['overzaki_id' => (string) $areaRaw['_id']]);
                    $area->fill($this->mapper->area($areaRaw, $areaPosition) + ['delivery_city_id' => $city->id])->save();
                    $this->report->record('delivery_areas', $area);

                    $areas[$area->id] = [$city->overzaki_id, $area->overzaki_id];
                }
            }
        });

        if ($probeFees) {
            $this->probeDeliveryFees($areas);
        }
    }

    /**
     * The delivery fee for an area is not published anywhere, so it is read
     * the only way Overzaki exposes it: by pricing one cheap, plain product
     * for a delivery to that area and noting what the checker charges.
     *
     * @param  array<int,array{0:string,1:string}>  $areas  local area id => [Overzaki city id, Overzaki area id]
     */
    protected function probeDeliveryFees(array $areas): void
    {
        $probe = Product::query()
            ->active()
            ->where('sell_price_fils', '>', 0)
            ->whereNotNull('overzaki_id')
            ->whereDoesntHave('optionGroups')
            ->orderBy('sell_price_fils')
            ->first();

        if ($probe === null) {
            $this->report->warn('No plain product to price a delivery with, so area fees were not read.');

            return;
        }

        $this->say('Reading the delivery fee for '.count($areas).' areas');

        $fees = [];
        $failed = 0;

        foreach (array_chunk($areas, self::FEE_BATCH, true) as $batch) {
            $responses = Http::pool(function (Pool $pool) use ($batch, $probe) {
                $requests = [];

                foreach ($batch as $areaId => [$cityOverzakiId, $areaOverzakiId]) {
                    $requests[] = $pool->as((string) $areaId)
                        ->withHeaders($this->headers())
                        ->connectTimeout(5)
                        ->timeout(30)
                        ->acceptJson()
                        ->asJson()
                        ->post($this->url(config('overzaki.endpoints.cartChecker')), [
                            'items' => [['productId' => $probe->overzaki_id, 'quantity' => 1]],
                            'address' => [
                                'type' => 'home',
                                'country' => config('overzaki.country_id'),
                                'city' => $cityOverzakiId,
                                'area' => $areaOverzakiId,
                            ],
                        ]);
                }

                return $requests;
            });

            foreach ($responses as $areaId => $response) {
                $data = $response instanceof Response && $response->successful() ? $response->json('data') : null;

                if (! is_array($data) || ! isset($data['deliveryFees'])) {
                    $failed++;

                    continue;
                }

                $fees[(int) $areaId] = Money::toFils($data['deliveryFees']);
                $this->observe($data);
            }
        }

        foreach ($fees as $areaId => $fils) {
            DeliveryArea::query()->whereKey($areaId)->update(['fee_fils' => $fils]);
        }

        if ($failed > 0) {
            $this->report->warn("The delivery fee could not be read for {$failed} area(s); they will use the store's default fee.");
        }
    }

    /**
     * Note, once, the store-wide numbers a real checkout quote carries.
     *
     * @param  array<string,mixed>  $quote
     */
    protected function observe(array $quote): void
    {
        if ($this->report->observed !== []) {
            return;
        }

        $this->report->observed = [
            'minimum_order_fils' => Money::toFils($quote['minimumOrderAmount'] ?? 0),
            'free_shipping_threshold_fils' => Money::toFils($quote['shippingWaiverAmount'] ?? 0),
            'cod_fee_fils' => Money::toFils($quote['cashOnDeliveryFee'] ?? 0),
            'vat_on_probe_fils' => Money::toFils($quote['vat'] ?? 0),
            'cash_on_delivery_available' => (bool) ($quote['availableCashOnDelivery'] ?? false),
        ];
    }

    // ---------------------------------------------------------------- addons

    protected function importAddons(): void
    {
        $response = $this->client->get('/service-addons/active');
        $rows = $response['data'] ?? (is_array($response) ? $response : []);

        foreach (array_values($rows) as $position => $raw) {
            if (! is_array($raw) || empty($raw['_id']) || ($raw['isDelete'] ?? false)) {
                continue;
            }

            $attributes = $this->mapper->addon($raw, $position);
            $attributes['image'] = $this->mirror($attributes['image'], 'addons');

            $addon = ServiceAddon::firstOrNew(['overzaki_id' => $attributes['overzaki_id']]);
            $addon->fill($attributes)->save();
            $this->report->record('service_addons', $addon);
        }
    }

    // --------------------------------------------------------------- helpers

    protected function mirror(?string $url, string $folder): ?string
    {
        return $this->mirrorImages ? $this->images->mirror($url, $folder) : $url;
    }

    /**
     * A slug that no other row holds, so two Overzaki documents that happen to
     * share one cannot overwrite each other.
     *
     * @param  class-string<Model>  $model
     */
    protected function freeSlug(string $model, string $slug, Model $current): string
    {
        $taken = $model::query()
            ->where('slug', $slug)
            ->when($current->exists, fn ($query) => $query->whereKeyNot($current->getKey()))
            ->exists();

        if (! $taken) {
            return $slug;
        }

        $this->report->warn('Two items share the slug "'.$slug.'"; one was renamed.');

        return $slug.'-'.substr((string) ($current->overzaki_id ?: sha1($slug)), -6);
    }

    /** @return array<string,string> */
    protected function headers(): array
    {
        return [
            'x-tenant-id' => config('overzaki.tenant_id'),
            'x-currency-id' => config('overzaki.currency_id'),
            'Accept-Language' => 'en',
        ];
    }

    protected function url(string $path): string
    {
        return rtrim(config('overzaki.base_url'), '/').'/'.ltrim($path, '/');
    }

    protected function say(string $message): void
    {
        if ($this->progress !== null) {
            ($this->progress)($message);
        }
    }
}
