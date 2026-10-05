<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\ProductQuantityTierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'product_id', 'min_quantity', 'discount_type', 'discount_fils',
    'discount_percent', 'free_delivery', 'label_ar', 'label_en',
])]
class ProductQuantityTier extends Model
{
    /** @use HasFactory<ProductQuantityTierFactory> */
    use HasFactory, HasLocalizedFields;

    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'discount_fils' => 'integer',
            'discount_percent' => 'decimal:2',
            'free_delivery' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** What this tier takes off a line that costs $lineTotalFils, never more than the line. */
    public function discountFor(int $lineTotalFils): int
    {
        $discount = match ($this->discount_type) {
            Product::DISCOUNT_FIXED => (int) $this->discount_fils,
            // Percentage in basis points, rounded half up to a whole fils.
            Product::DISCOUNT_PERCENTAGE => intdiv($lineTotalFils * (int) round((float) $this->discount_percent * 100) + 5000, 10000),
            default => 0,
        };

        return min($discount, $lineTotalFils);
    }
}
