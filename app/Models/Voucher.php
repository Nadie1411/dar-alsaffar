<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedFields;
use Carbon\CarbonInterface;
use Database\Factories\VoucherFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'code', 'name_ar', 'name_en', 'type', 'percent', 'amount_fils',
    'max_discount_fils', 'min_subtotal_fils', 'starts_at', 'ends_at',
    'usage_limit', 'usage_limit_per_customer', 'is_active', 'is_public', 'overzaki_id',
])]
class Voucher extends Model
{
    /** @use HasFactory<VoucherFactory> */
    use HasFactory, HasLocalizedFields;

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    public const TYPE_FREE_SHIPPING = 'free_shipping';

    protected function casts(): array
    {
        return [
            'percent' => 'decimal:2',
            'amount_fils' => 'integer',
            'max_discount_fils' => 'integer',
            'min_subtotal_fils' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'usage_limit_per_customer' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /** Codes are typed by people, so they are matched without regard to case or stray spaces. */
    public static function normaliseCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value === null || trim($value) === '' ? null : self::normaliseCode($value);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Active and inside its dates at the given moment. */
    public function isLive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->is_active
            && ($this->starts_at === null || $at->gte($this->starts_at))
            && ($this->ends_at === null || $at->lte($this->ends_at));
    }
}
