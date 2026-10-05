<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id', 'product_id', 'name_ar', 'name_en', 'sku', 'image',
    'quantity', 'unit_price_fils', 'total_fils', 'options', 'is_gift',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory, HasLocalizedFields;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_fils' => 'integer',
            'total_fils' => 'integer',
            'options' => 'array',
            'is_gift' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
