<?php

namespace App\Services\Overzaki;

use App\Contracts\Store\Catalog;
use App\Services\Overzaki\DTO\Product;
use App\Services\Store\Concerns\BrowsesProducts;
use App\Support\Loc;
use Illuminate\Support\Facades\Cache;

/**
 * Read access to the Dar Al Saffar catalogue.
 *
 * The storefront API paginates and sorts, but it does not filter by category,
 * price or availability. The catalogue is small enough (tens of products) that
 * we pull it once, cache it briefly, and apply facets here — which also lets
 * the listing pages offer filters the upstream API has no parameters for.
 *
 * If the catalogue ever grows into the thousands this should move back to
 * server-side filtering; see filterable() for the single place to change.
 */
class CatalogService implements Catalog
{
    use BrowsesProducts;

    /** Upper bound on one catalogue fetch. */
    protected const BULK_PAGE_SIZE = 250;

    public function __construct(protected OverzakiClient $client) {}

    // ------------------------------------------------------------- catalogue

    /** @return array<int,Product> */
    public function all(): array
    {
        $rows = Cache::remember('ovz:products:all', config('overzaki.cache.catalog'), function () {
            $response = $this->client->post(
                config('overzaki.endpoints.products'),
                [],
                ['pageSize' => self::BULK_PAGE_SIZE, 'pageNumber' => 1, 'sort' => '-totalOrders']
            );

            return $this->withOptionPricing($response['data'] ?? []);
        });

        return Product::collect($rows);
    }

    /**
     * Some products carry no price of their own — an oud sold by the tola, for
     * instance, prices every weight as an option. The catalogue list omits
     * option groups entirely, so those products would render as "0 KWD".
     *
     * Their detail records are fetched concurrently and merged back in, which
     * lets the card show an honest "from" price.
     *
     * @param  array<int,array>  $rows
     * @return array<int,array>
     */
    protected function withOptionPricing(array $rows): array
    {
        $needsOptions = [];

        foreach ($rows as $index => $row) {
            $pricedByOptions = (float) ($row['sellPrice'] ?? 0) <= 0
                && ($row['isOptionExists'] ?? false)
                && ! empty($row['slug']);

            if ($pricedByOptions) {
                $needsOptions[$index] = config('overzaki.endpoints.product').rawurlencode($row['slug']);
            }
        }

        if ($needsOptions === []) {
            return $rows;
        }

        foreach ($this->client->pool($needsOptions) as $index => $detail) {
            $options = $detail['product']['options'] ?? null;

            if (is_array($options)) {
                $rows[$index]['options'] = $options;
            }
        }

        return $rows;
    }

    public function find(string $slug): ?Product
    {
        $raw = Cache::remember("ovz:product:{$slug}", config('overzaki.cache.catalog'), function () use ($slug) {
            $response = $this->client->get(config('overzaki.endpoints.product').rawurlencode($slug));

            return $response['product'] ?? null;
        });

        return Product::make($raw);
    }

    /** @return array<int,Product> */
    public function related(string $productId, int $limit = 8): array
    {
        $rows = Cache::remember("ovz:related:{$productId}", config('overzaki.cache.catalog'), function () use ($productId) {
            $response = $this->client->get(
                config('overzaki.endpoints.relatedProducts').$productId,
                ['pageSize' => 12, 'pageNumber' => 1]
            );

            return $response['data'] ?? [];
        });

        $related = Product::collect($rows);

        // Some products have no curated relations; fall back to the same
        // category so the slot is never empty on a live product page.
        if ($related === []) {
            $product = collect($this->all())->first(fn (Product $p) => $p->id() === $productId);
            $categoryId = $product?->primaryCategory()['id'] ?? null;

            if ($categoryId) {
                $related = array_values(array_filter(
                    $this->all(),
                    fn (Product $p) => $p->id() !== $productId
                        && collect($p->categories())->contains(fn ($c) => $c['id'] === $categoryId)
                ));
            }
        }

        return array_slice($related, 0, $limit);
    }

    // ------------------------------------------------------------- taxonomy

    /** @return array<int,array<string,mixed>> */
    public function categories(bool $topLevelOnly = false): array
    {
        $rows = Cache::remember('ovz:categories', config('overzaki.cache.taxonomy'), function () {
            $response = $this->client->get(config('overzaki.endpoints.categories'));

            return $response['data'] ?? $response;
        });

        $categories = collect(is_array($rows) ? $rows : [])
            ->filter(fn ($c) => is_array($c) && ($c['isActive'] ?? true) && ! ($c['isDelete'] ?? false))
            ->map(fn ($c) => [
                'id' => (string) ($c['_id'] ?? ''),
                'name' => Loc::text($c['name'] ?? null),
                'slug' => (string) ($c['slug'] ?? ''),
                'level' => (int) ($c['level'] ?? 1),
                'parentId' => $c['parentId'] ?? null,
                'image' => $c['image'] ?? null,
                'icon' => $c['icon'] ?? null,
                'featured' => (bool) ($c['isFeatured'] ?? false),
                'sort' => (int) ($c['sortIndex'] ?? 0),
            ])
            ->filter(fn ($c) => $c['id'] !== '' && $c['name'] !== '')
            ->sortBy('sort')
            ->values();

        if ($topLevelOnly) {
            $categories = $categories->where('level', 1)->values();
        }

        return $categories->all();
    }
}
