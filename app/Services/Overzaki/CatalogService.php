<?php

namespace App\Services\Overzaki;

use App\Services\Overzaki\DTO\Product;
use App\Support\Loc;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
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
class CatalogService
{
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

    public function category(string $slug): ?array
    {
        return collect($this->categories())->firstWhere('slug', $slug);
    }

    /**
     * Category list decorated with how many live products sit in each.
     *
     * A category with no artwork of its own borrows the photograph of its
     * best-selling product, so the collection card shows something real from
     * that collection instead of an empty panel.
     */
    public function categoriesWithCounts(bool $topLevelOnly = true): array
    {
        $products = $this->all();

        return collect($this->categories($topLevelOnly))
            ->map(function (array $category) use ($products) {
                $inCategory = collect($products)->filter(
                    fn (Product $p) => collect($p->categories())->contains(fn ($c) => $c['id'] === $category['id'])
                );

                $category['count'] = $inCategory->count();

                if (empty($category['image'])) {
                    $category['image'] = $inCategory
                        ->sortByDesc(fn (Product $p) => $p->totalOrders())
                        ->map(fn (Product $p) => $p->image())
                        ->filter()
                        ->first();

                    // Flag it so the card can treat a product shot differently
                    // from purpose-shot category artwork.
                    $category['imageFromProduct'] = ! empty($category['image']);
                }

                return $category;
            })
            ->filter(fn ($c) => $c['count'] > 0)
            ->values()
            ->all();
    }

    // ------------------------------------------------------------ discovery

    /** @return array<int,Product> */
    public function bestSellers(int $limit = 8): array
    {
        return collect($this->all())
            ->sortByDesc(fn (Product $p) => $p->totalOrders())
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array<int,Product> */
    public function newArrivals(int $limit = 8): array
    {
        return collect($this->all())
            ->sortByDesc(fn (Product $p) => $p->raw['createdAt'] ?? '')
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array<int,Product> */
    public function onOffer(int $limit = 8): array
    {
        return collect($this->all())
            ->filter(fn (Product $p) => $p->hasDiscount())
            ->sortByDesc(fn (Product $p) => $p->discountPercent())
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array<int,Product> */
    public function featured(int $limit = 8): array
    {
        $featured = collect($this->all())->filter(fn (Product $p) => $p->isFeatured());

        return ($featured->isEmpty() ? collect($this->bestSellers($limit)) : $featured)
            ->take($limit)->values()->all();
    }

    // -------------------------------------------------------------- queries

    /**
     * Search across both localised names, the slug and the SKU so an Arabic
     * shopper and an English one both find the same bottle.
     *
     * @return array<int,Product>
     */
    public function search(string $term, int $limit = 24): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        return collect($this->all())
            ->filter(fn (Product $p) => $this->matches($p, $term))
            ->take($limit)
            ->values()
            ->all();
    }

    protected function matches(Product $product, string $term): bool
    {
        $haystacks = [
            Loc::text($product->raw['title'] ?? null, 'ar'),
            Loc::text($product->raw['title'] ?? null, 'en'),
            $product->slug(),
            (string) $product->sku(),
            $product->excerpt(400),
        ];

        foreach ($product->categories() as $category) {
            $haystacks[] = $category['name'];
        }

        $needle = $this->normalise($term);

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && str_contains($this->normalise($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold Arabic orthography so "عطر" matches "عطــر", أ/إ/آ match ا, and
     * تاء مربوطة matches هاء — the variations shoppers actually type.
     */
    protected function normalise(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0640}\x{06D6}-\x{06ED}]/u', '', $value) ?? $value;

        return strtr($value, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و', 'ة' => 'ه',
        ]);
    }

    /**
     * The single entry point for listing pages: applies every facet, sorts,
     * and paginates.
     *
     * @param  array{category?:string,min?:float,max?:float,availability?:string,offers?:bool,sort?:string,q?:string}  $filters
     */
    public function filterable(array $filters, int $perPage = 12, int $page = 1): LengthAwarePaginator
    {
        $products = collect($this->all());

        if (! empty($filters['q'])) {
            $products = $products->filter(fn (Product $p) => $this->matches($p, $filters['q']));
        }

        if (! empty($filters['category'])) {
            $slugs = (array) $filters['category'];
            $ids = collect($this->categories())->whereIn('slug', $slugs)->pluck('id')->all();

            $products = $products->filter(
                fn (Product $p) => collect($p->categories())->contains(fn ($c) => in_array($c['id'], $ids, true))
            );
        }

        if (isset($filters['min']) && $filters['min'] !== null && $filters['min'] !== '') {
            $products = $products->filter(fn (Product $p) => $p->price() >= (float) $filters['min']);
        }

        if (isset($filters['max']) && $filters['max'] !== null && $filters['max'] !== '') {
            $products = $products->filter(fn (Product $p) => $p->price() <= (float) $filters['max']);
        }

        if (($filters['availability'] ?? null) === 'in') {
            $products = $products->filter(fn (Product $p) => $p->inStock());
        }

        if (! empty($filters['offers'])) {
            $products = $products->filter(fn (Product $p) => $p->hasDiscount());
        }

        $products = $this->sort($products, $filters['sort'] ?? 'recommended');

        return new LengthAwarePaginator(
            $products->forPage($page, $perPage)->values()->all(),
            $products->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /** @param  Collection<int,Product>  $products */
    protected function sort(Collection $products, string $sort): Collection
    {
        return (match ($sort) {
            'price-asc' => $products->sortBy(fn (Product $p) => $p->price()),
            'price-desc' => $products->sortByDesc(fn (Product $p) => $p->price()),
            'newest' => $products->sortByDesc(fn (Product $p) => $p->raw['createdAt'] ?? ''),
            'name' => $products->sortBy(fn (Product $p) => $p->name(), SORT_NATURAL | SORT_FLAG_CASE),
            'discount' => $products->sortByDesc(fn (Product $p) => $p->discountPercent()),
            default => $products->sortByDesc(fn (Product $p) => $p->totalOrders()),
        })->values();
    }

    /** Price bounds for the filter panel, derived from live catalogue prices. */
    public function priceRange(): array
    {
        $prices = collect($this->all())->map(fn (Product $p) => $p->price())->filter(fn ($p) => $p > 0);

        return [
            'min' => (int) floor($prices->min() ?? 0),
            'max' => (int) ceil($prices->max() ?? 0),
        ];
    }
}
