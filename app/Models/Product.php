<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Carbon\CarbonInterface;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug', 'sku', 'name_ar', 'name_en', 'description_ar', 'description_en',
    'sell_price_fils', 'discount_type', 'discount_fils', 'discount_percent',
    'discount_starts_at', 'discount_ends_at',
    'track_stock', 'stock', 'low_stock_threshold', 'max_per_order',
    'main_image', 'video', 'tags',
    'is_active', 'is_featured', 'is_new', 'is_popular', 'cod_enabled', 'sort_order',
    'sales_count', 'rating_average', 'rating_count', 'overzaki_id',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasLocalizedFields;

    public const DISCOUNT_NONE = 'none';

    public const DISCOUNT_FIXED = 'fixed';

    public const DISCOUNT_PERCENTAGE = 'percentage';

    protected function casts(): array
    {
        return [
            'sell_price_fils' => 'integer',
            'discount_fils' => 'integer',
            'discount_percent' => 'decimal:2',
            'discount_starts_at' => 'datetime',
            'discount_ends_at' => 'datetime',
            'track_stock' => 'boolean',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'tags' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'is_popular' => 'boolean',
            'cod_enabled' => 'boolean',
            'sales_count' => 'integer',
            'rating_average' => 'decimal:2',
        ];
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(OptionGroup::class)->orderBy('sort_order');
    }

    public function quantityTiers(): HasMany
    {
        return $this->hasMany(ProductQuantityTier::class)->orderBy('min_quantity');
    }

    /** The tier a line of this many units reaches: the highest one whose minimum it meets. */
    public function quantityTierFor(int $quantity): ?ProductQuantityTier
    {
        return $this->quantityTiers
            ->filter(fn (ProductQuantityTier $tier) => $tier->min_quantity <= $quantity)
            ->sortByDesc('min_quantity')
            ->first();
    }

    public function related(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_related', 'product_id', 'related_product_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    // ---------------------------------------------------------------- money
    //
    // This is the one place a product's own price is worked out. Everything
    // is whole fils, so there is no rounding drift to disagree about.

    /** Whether the product's own discount applies at the given moment. */
    public function discountIsActive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        $amount = match ($this->discount_type) {
            self::DISCOUNT_FIXED => $this->discount_fils,
            self::DISCOUNT_PERCENTAGE => (float) $this->discount_percent,
            default => 0,
        };

        if ($amount <= 0) {
            return false;
        }

        if ($this->discount_starts_at !== null && $at->lt($this->discount_starts_at)) {
            return false;
        }

        return $this->discount_ends_at === null || $at->lte($this->discount_ends_at);
    }

    /** The unit price after the product's own live discount, before any options. */
    public function priceFils(?CarbonInterface $at = null): int
    {
        $list = (int) $this->sell_price_fils;

        if (! $this->discountIsActive($at)) {
            return $list;
        }

        if ($this->discount_type === self::DISCOUNT_FIXED) {
            return max($list - (int) $this->discount_fils, 0);
        }

        // Percentage in basis points, rounded half up to a whole fils.
        $basisPoints = (int) round((float) $this->discount_percent * 100);
        $off = intdiv($list * $basisPoints + 5000, 10000);

        return max($list - $off, 0);
    }

    // ---------------------------------------------------------------- stock

    public function inStock(): bool
    {
        return ! $this->track_stock || $this->stock > 0;
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->stock > 0 && $this->stock <= $this->low_stock_threshold;
    }
}
