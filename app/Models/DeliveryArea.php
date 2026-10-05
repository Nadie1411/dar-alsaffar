<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\DeliveryAreaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['delivery_city_id', 'name_ar', 'name_en', 'fee_fils', 'sort_order', 'is_active', 'overzaki_id'])]
class DeliveryArea extends Model
{
    /** @use HasFactory<DeliveryAreaFactory> */
    use HasFactory, HasLocalizedFields;

    protected function casts(): array
    {
        return [
            'fee_fils' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(DeliveryCity::class, 'delivery_city_id');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
