<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\DeliveryCityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name_ar', 'name_en', 'sort_order', 'is_active', 'overzaki_id'])]
class DeliveryCity extends Model
{
    /** @use HasFactory<DeliveryCityFactory> */
    use HasFactory, HasLocalizedFields;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function areas(): HasMany
    {
        return $this->hasMany(DeliveryArea::class)->orderBy('sort_order');
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
