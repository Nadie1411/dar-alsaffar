<?php

namespace App\Services\Store\Concerns;

use App\Services\Overzaki\DTO\Product;
use App\Support\Loc;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Everything a catalogue does with products once it has them: ranking,
 * searching, filtering, sorting and paginating.
 *
 * It is shared between the Overzaki-backed catalogue and the one that reads
 * this application's own database, so the two can never rank or search
 * differently. A catalogue supplies the products and the category list; the
 * rest is here.
 */
trait BrowsesProducts
{
    /** @return array<int,Product> */
    abstract public function all(): array;

    /** @return array<int,array<string,mixed>> */
    abstract public function categories(bool $topLevelOnly = false): array;

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
