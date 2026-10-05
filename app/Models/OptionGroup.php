<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\OptionGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'product_id', 'name_ar', 'name_en', 'layout', 'is_required',
    'min_choices', 'max_choices', 'sort_order', 'is_active', 'overzaki_id',
])]
class OptionGroup extends Model
{
    /** @use HasFactory<OptionGroupFactory> */
    use HasFactory, HasLocalizedFields;

    public const LAYOUT_RADIO = 'radio';

    public const LAYOUT_CHECKBOX = 'checkbox';

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'min_choices' => 'integer',
            'max_choices' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(OptionValue::class)->orderBy('sort_order');
    }
}
