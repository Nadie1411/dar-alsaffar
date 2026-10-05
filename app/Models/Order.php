<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'customer_id', 'customer_name', 'customer_email', 'customer_phone',
    'status', 'payment_method', 'payment_status',
    'currency', 'subtotal_fils', 'discount_fils', 'delivery_fee_fils',
    'addons_total_fils', 'cod_fee_fils', 'total_fils', 'voucher_code',
    'delivery_city_id', 'delivery_area_id',
    'city_name_ar', 'city_name_en', 'area_name_ar', 'area_name_en',
    'block', 'street', 'avenue', 'building', 'floor', 'apartment',
    'addons', 'notes', 'admin_notes', 'locale',
    'placed_at', 'paid_at', 'delivered_at', 'cancelled_at', 'overzaki_id',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    public const PAYMENT_COD = 'cod';

    public const PAYMENT_ONLINE = 'online';

    /** The first number issued, so the shop's orders do not look like they started at 1. */
    public const NUMBER_OFFSET = 100_000;

    protected static function booted(): void
    {
        // The number comes from the id, so it can only be set once the row
        // exists — in the same transaction as the insert.
        static::created(function (self $order): void {
            $order->forceFill(['number' => 'DS-'.(self::NUMBER_OFFSET + $order->id)])->saveQuietly();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal_fils' => 'integer',
            'discount_fils' => 'integer',
            'delivery_fee_fils' => 'integer',
            'addons_total_fils' => 'integer',
            'cod_fee_fils' => 'integer',
            'total_fils' => 'integer',
            'addons' => 'array',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('created_at')->orderBy('id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(DeliveryCity::class, 'delivery_city_id');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(DeliveryArea::class, 'delivery_area_id');
    }

    /**
     * Orders that count as sales: placed and not cancelled. An online order
     * still waiting for its payment has not been sold yet.
     */
    #[Scope]
    protected function counted(Builder $query): void
    {
        $query->whereNotIn('status', [OrderStatus::PendingPayment->value, OrderStatus::Cancelled->value]);
    }

    /** Placed between two moments, however their time zones are expressed. */
    #[Scope]
    protected function placedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $zone = config('app.timezone');

        $query->whereBetween('placed_at', [$from->copy()->timezone($zone), $to->copy()->timezone($zone)]);
    }

    public function isCashOnDelivery(): bool
    {
        return $this->payment_method === self::PAYMENT_COD;
    }

    /** Language-aware snapshot of where the order is going. */
    public function localizedAddress(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return collect([
            $locale === 'ar' ? $this->city_name_ar : $this->city_name_en,
            $locale === 'ar' ? $this->area_name_ar : $this->area_name_en,
            $this->block ? __('storefront.checkout.block').' '.$this->block : null,
            $this->street,
            $this->avenue,
            $this->building,
        ])->filter()->implode('، ');
    }
}
