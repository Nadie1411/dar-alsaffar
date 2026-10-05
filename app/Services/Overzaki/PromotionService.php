<?php

namespace App\Services\Overzaki;

use App\Contracts\Store\Promotions;
use App\Support\Loc;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the store's live promotions.
 *
 * Every offer — buy-x-get-y, percentage, fixed amount, free shipping — is
 * created and scoped in the Overzaki dashboard. Nothing is defined here, so a
 * promotion that has expired, sold out or been switched off simply stops
 * appearing. This class only decides how to describe what is already true.
 */
class PromotionService implements Promotions
{
    public const BUY_X_GET_Y = 'buy_x_get_y';

    public const FREE_SHIPPING = 'free_shipping';

    public const PERCENTAGE = 'percentage_off';

    public const FIXED_AMOUNT = 'fixed_amount';

    public const AUTOMATIC = 'automatic';

    public function __construct(protected OverzakiClient $client) {}

    /**
     * Offers the store has published for the storefront.
     *
     * @return array<int,array<string,mixed>>
     */
    public function public(): array
    {
        $rows = Cache::remember('ovz:vouchers:public', 300, function () {
            $response = $this->client->get(config('overzaki.endpoints.publicVouchers'));

            return $response['data'] ?? (is_array($response) ? $response : []);
        });

        return collect(is_array($rows) ? $rows : [])
            ->filter(fn ($v) => is_array($v) && ($v['status'] ?? false) && ! ($v['isDeleted'] ?? false))
            ->filter(fn ($v) => $this->isLive($v))
            ->map(fn ($v) => $this->present($v))
            ->values()
            ->all();
    }

    /** The single offer worth putting in the announcement bar, if any. */
    public function headline(): ?array
    {
        $offers = $this->public();

        // A gift offer says more than a discount code, so it leads.
        usort($offers, fn ($a, $b) => ($b['type'] === self::BUY_X_GET_Y) <=> ($a['type'] === self::BUY_X_GET_Y));

        return $offers[0] ?? null;
    }

    /** @return array<string,mixed> */
    protected function present(array $voucher): array
    {
        $type = $this->typeOf($voucher);

        return [
            'id' => (string) ($voucher['_id'] ?? ''),
            'type' => $type,
            'name' => Loc::text($voucher['name'] ?? null),
            'code' => ($voucher['method'] ?? null) === 'code' ? ($voucher['code'] ?? null) : null,
            'automatic' => ($voucher['method'] ?? null) !== 'code',
            'discountType' => $voucher['discountType'] ?? null,
            'discountValue' => (float) ($voucher['discountValue'] ?? 0),
            'xQuantity' => (int) ($voucher['xQuantity'] ?? 0),
            'yQuantity' => (int) ($voucher['yQuantity'] ?? 0),
            'threshold' => (float) ($voucher['thresholdAmount'] ?? 0),
            'endDate' => $voucher['endDate'] ?? null,
            'scope' => $this->scopeOf($voucher),
            'headline' => $this->headlineFor($voucher, $type),
        ];
    }

    protected function typeOf(array $voucher): string
    {
        if ((int) ($voucher['xQuantity'] ?? 0) > 0 && (int) ($voucher['yQuantity'] ?? 0) > 0) {
            return self::BUY_X_GET_Y;
        }

        return match ($voucher['voucherType'] ?? '') {
            'free_shipping' => self::FREE_SHIPPING,
            'buy_x_get_y' => self::BUY_X_GET_Y,
            default => ($voucher['discountType'] ?? '') === 'percentage'
                ? self::PERCENTAGE
                : self::FIXED_AMOUNT,
        };
    }

    /**
     * Where the offer applies, in words the shopper understands:
     * the whole store, a collection, or chosen products.
     */
    protected function scopeOf(array $voucher): array
    {
        if ($voucher['allProducts'] ?? false) {
            return ['kind' => 'store', 'label' => __('storefront.promo.scopeStore'), 'items' => []];
        }

        if (($voucher['appliesTo'] ?? null) === 'categories' && ! ($voucher['allCategories'] ?? false)) {
            $names = collect($voucher['categories'] ?? [])
                ->map(fn ($c) => Loc::text($c['name'] ?? null))
                ->filter()
                ->values()
                ->all();

            return ['kind' => 'categories', 'label' => __('storefront.promo.scopeCategories'), 'items' => $names];
        }

        if (! empty($voucher['products'])) {
            return ['kind' => 'products', 'label' => __('storefront.promo.scopeProducts'), 'items' => []];
        }

        return ['kind' => 'store', 'label' => __('storefront.promo.scopeStore'), 'items' => []];
    }

    protected function headlineFor(array $voucher, string $type): string
    {
        return match ($type) {
            self::BUY_X_GET_Y => __('storefront.promo.buyXGetY', [
                'x' => (int) ($voucher['xQuantity'] ?? 2),
                'y' => (int) ($voucher['yQuantity'] ?? 1),
            ]),
            self::FREE_SHIPPING => __('storefront.promo.freeShipping'),
            self::PERCENTAGE => __('storefront.promo.percentOff', [
                'value' => rtrim(rtrim(number_format((float) ($voucher['discountValue'] ?? 0), 2, '.', ''), '0'), '.'),
            ]),
            default => Loc::text($voucher['name'] ?? null),
        };
    }

    /** Respect the offer's own date window; an expired offer is not shown. */
    protected function isLive(array $voucher): bool
    {
        if ($voucher['unlimatedDuration'] ?? false) {
            return true;
        }

        $now = time();

        $start = $this->stamp($voucher['startDate'] ?? null, $voucher['startTime'] ?? '00:00');
        $end = $this->stamp($voucher['endDate'] ?? null, $voucher['endTime'] ?? '23:59');

        if ($start !== null && $now < $start) {
            return false;
        }

        if ($end !== null && $now > $end) {
            return false;
        }

        return true;
    }

    protected function stamp(?string $date, ?string $time): ?int
    {
        if (! $date) {
            return null;
        }

        $parsed = strtotime(trim($date.' '.($time ?: '00:00')));

        return $parsed === false ? null : $parsed;
    }
}
