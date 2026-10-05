<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\OptionValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['option_group_id', 'name_ar', 'name_en', 'price_fils', 'image', 'sort_order', 'is_active', 'overzaki_id'])]
class OptionValue extends Model
{
    /** @use HasFactory<OptionValueFactory> */
    use HasFactory, HasLocalizedFields;

    protected function casts(): array
    {
        return [
            'price_fils' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }
}
