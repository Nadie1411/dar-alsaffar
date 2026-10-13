<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Database\Factories\OptionValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['option_group_id', 'product_id', 'name_ar', 'name_en', 'price_fils', 'image', 'sort_order', 'is_active', 'overzaki_id'])]
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

    /** The shop's own product this value stands for, when it is a piece of a set. */
    public function piece(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * A piece is as current as the product it stands for: renaming the product
     * renames it here too. Any other value keeps its own name.
     *
     * @return array{ar:string,en:string}
     */
    public function nameMap(): array
    {
        return $this->piece?->localizedMap('name') ?? $this->localizedMap('name');
    }

    /**
     * Where a piece sits in the shop (its top-level collection), so two products
     * that share a name — a perfume and its spray — can be told apart.
     *
     * @return array{ar:string,en:string}|null
     */
    public function kickerMap(): ?array
    {
        $categories = $this->piece?->categories->filter(fn (Category $category) => $category->is_active);

        return $categories?->firstWhere('parent_id', null)?->localizedMap('name')
            ?? $categories?->first()?->localizedMap('name');
    }

    public function imagePath(): ?string
    {
        return $this->piece !== null ? $this->piece->main_image : $this->image;
    }

    /** Whether a shopper may pick it: switched on, and — for a piece — still on sale. */
    public function isSelectable(): bool
    {
        return $this->is_active && ($this->product_id === null || $this->piece?->is_active === true);
    }

    /** A piece the shop has run out of. */
    public function isSoldOut(): bool
    {
        return $this->piece !== null && ! $this->piece->inStock();
    }
}
