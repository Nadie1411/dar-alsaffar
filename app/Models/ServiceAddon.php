<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\ServiceAddonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name_ar', 'name_en', 'description_ar', 'description_en',
    'price_fils', 'image', 'sort_order', 'is_active', 'overzaki_id',
])]
class ServiceAddon extends Model
{
    /** @use HasFactory<ServiceAddonFactory> */
    use HasFactory, HasLocalizedFields;

    protected function casts(): array
    {
        return [
            'price_fils' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
