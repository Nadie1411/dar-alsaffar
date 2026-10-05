<?php

namespace App\Services\Store;

use App\Contracts\Store\Catalog;
use App\Models\Category;
use App\Models\Product;
use App\Services\Overzaki\DTO\Product as ProductView;
use App\Services\Store\Concerns\BrowsesProducts;
use App\Support\Media;

/**
 * The catalogue, read from this application's own database.
 *
 * Presents each product as the same view model the Overzaki-backed catalogue
 * produces, and shares its ranking, search and filtering, so a shopper cannot
 * tell which of the two is behind the page.
 *
 * One instance serves a whole request, so the product list is read once and
 * reused by the header menu, the footer and the page itself.
 */
class LocalCatalog implements Catalog
{
    use BrowsesProducts;

    /** @var array<int,ProductView>|null */
    protected ?array $products = null;

    /** @var array<int,array<string,mixed>>|null */
    protected ?array $categoryRows = null;

    public function __construct(protected ProductPresenter $presenter) {}

    /** @return array<int,ProductView> */
    public function all(): array
    {
        return $this->products ??= ProductView::collect(
            Product::query()
                ->active()
                ->with(ProductPresenter::RELATIONS)
                ->orderByDesc('sales_count')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (Product $product) => $this->presenter->raw($product))
                ->all()
        );
    }

    public function find(string $slug): ?ProductView
    {
        $product = Product::query()
            ->active()
            ->where('slug', $slug)
            ->with(ProductPresenter::RELATIONS)
            ->first();

        return $product === null ? null : ProductView::make($this->presenter->raw($product));
    }

    /** @return array<int,ProductView> */
    public function related(string $productId, int $limit = 8): array
    {
        $product = Product::query()->active()->with('categories')->find($productId);

        if ($product === null) {
            return [];
        }

        $byId = collect($this->all())->keyBy(fn (ProductView $view) => $view->id());

        // Curated relations first, in the order the shop set them.
        $related = $product->related()->active()->pluck('products.id')
            ->map(fn ($id) => $byId->get((string) $id))
            ->filter()
            ->values();

        // Some products have none; fall back to the same category so the slot
        // is never empty on a live product page.
        if ($related->isEmpty()) {
            $categoryIds = $product->categories->pluck('id')->map(fn ($id) => (string) $id)->all();

            $related = $byId->filter(fn (ProductView $view) => $view->id() !== (string) $product->id
                && collect($view->categories())->contains(fn ($category) => in_array($category['id'], $categoryIds, true)))
                ->values();
        }

        return $related->take($limit)->values()->all();
    }

    /** @return array<int,array<string,mixed>> */
    public function categories(bool $topLevelOnly = false): array
    {
        $rows = collect($this->categoryRows ??= Category::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $category) => [
                'id' => (string) $category->id,
                'name' => $category->localized('name'),
                'slug' => $category->slug,
                'level' => $category->parent_id === null ? 1 : 2,
                'parentId' => $category->parent_id === null ? null : (string) $category->parent_id,
                'image' => Media::url($category->image),
                'icon' => Media::url($category->icon),
                'featured' => $category->is_featured,
                'sort' => $category->sort_order,
            ])
            ->filter(fn (array $category) => $category['name'] !== '')
            ->values()
            ->all());

        return ($topLevelOnly ? $rows->where('level', 1) : $rows)->values()->all();
    }
}
