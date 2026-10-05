<?php

namespace App\Services\Store;

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Support\Html;
use App\Support\Media;
use App\Support\Money;

/**
 * Turns a product row into the document the storefront's view model reads.
 *
 * The shape is the one the views were built against, so none of them had to
 * change when the shop moved onto its own database. Prices go out in dinars,
 * but they are worked out in whole fils by the model first: a discount is
 * emitted as the plain amount that takes the list price to exactly what the
 * pricing engine will charge, so the price on a product card can never differ
 * from the price in the basket.
 */
class ProductPresenter
{
    /**
     * Load everything the presenter reads in one go. Presenting a product that
     * has not had these loaded would run a query per relation per product.
     */
    public const RELATIONS = ['images', 'categories', 'optionGroups.values', 'quantityTiers'];

    /**
     * @return array<string,mixed>
     */
    public function raw(Product $product): array
    {
        $listFils = (int) $product->sell_price_fils;
        $discountActive = $product->discountIsActive();
        $stockCap = $this->quantityCap($product);

        return [
            '_id' => (string) $product->id,
            'id' => (string) $product->id,
            'slug' => $product->slug,
            'title' => $product->localizedMap('name'),
            'description' => array_map($this->descriptionHtml(...), $product->localizedMap('description')),
            'sku' => $product->sku,
            'symbol' => Money::KWD,

            'sellPrice' => Money::fromFils($listFils),
            'discountType' => 'fixed_amount',
            'discountValue' => $discountActive ? Money::fromFils($listFils - $product->priceFils()) : 0,

            'mainImage' => Media::url($product->main_image),
            'images' => $product->images->map(fn ($image) => Media::url($image->path))->filter()->values()->all(),
            'video' => Media::url($product->video),
            'categories' => $product->categories
                ->filter(fn (Category $category) => $category->is_active)
                ->map(fn (Category $category) => $this->category($category))
                ->values()
                ->all(),
            'tags' => $product->tags ?? [],

            'isStockLimited' => $product->track_stock,
            'isUnLimited' => ! $product->track_stock,
            'isStockEmpty' => $product->track_stock && $product->stock <= 0,
            'quantity' => $product->stock,
            'isLowStock' => $product->isLowStock(),
            // Only worth telling the shopper how many are left once it is few.
            'isQuantityAvailableToShow' => $product->isLowStock(),
            'unlimitedQuantityPerUser' => $stockCap === null,
            'maxQuantityPerUser' => $stockCap ?? 0,

            'isNew' => $product->is_new,
            'isFeatured' => $product->is_featured,
            'isPopular' => $product->is_popular,
            'isVarientExists' => false,
            'isOptionExists' => $product->optionGroups->isNotEmpty(),
            'rate' => (float) $product->rating_average,
            'count' => $product->rating_count,
            'totalOrders' => $product->sales_count,
            'createdAt' => $product->created_at?->toIso8601String(),

            'options' => $product->optionGroups
                ->map(fn (OptionGroup $group) => $this->optionGroup($group))
                ->values()
                ->all(),
        ];
    }

    /**
     * A description typed as plain text is shown as paragraphs; one with markup
     * in it was already cleaned down to safe tags when it was saved.
     */
    protected function descriptionHtml(string $description): string
    {
        return str_contains($description, '<') ? $description : Html::paragraphs($description);
    }

    /**
     * The most a shopper can put in one order: the product's own limit, or the
     * stock on hand when stock is tracked, whichever is lower.
     */
    protected function quantityCap(Product $product): ?int
    {
        $caps = array_filter([
            $product->max_per_order,
            $product->track_stock ? max($product->stock, 0) : null,
        ], fn ($cap) => $cap !== null);

        return $caps === [] ? null : min($caps);
    }

    /**
     * @return array<string,mixed>
     */
    protected function category(Category $category): array
    {
        return [
            '_id' => (string) $category->id,
            'parentId' => $category->parent_id === null ? null : (string) $category->parent_id,
            'level' => $category->parent_id === null ? 1 : 2,
            'name' => $category->localizedMap('name'),
            'slug' => $category->slug,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function optionGroup(OptionGroup $group): array
    {
        return [
            '_id' => (string) $group->id,
            'name' => $group->localizedMap('name'),
            'layout' => $group->layout,
            'isRequired' => $group->is_required,
            'minimumChoises' => $group->min_choices,
            'maximumChoises' => $group->max_choices,
            'isDelete' => ! $group->is_active,
            'values' => $group->values->map(fn (OptionValue $value) => [
                '_id' => (string) $value->id,
                'name' => $value->localizedMap('name'),
                'image' => Media::url($value->image),
                'price' => Money::fromFils($value->price_fils),
                'isActive' => $value->is_active,
                'isDelete' => false,
            ])->values()->all(),
        ];
    }
}
