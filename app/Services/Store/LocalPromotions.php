<?php

namespace App\Services\Store;

use App\Contracts\Store\Promotions;
use App\Models\Voucher;
use App\Services\Overzaki\PromotionService;
use App\Support\Money;

/**
 * The offers the shop has chosen to announce — public vouchers that are live.
 */
class LocalPromotions implements Promotions
{
    /** @return array<int,array<string,mixed>> */
    public function public(): array
    {
        return Voucher::query()
            ->active()
            ->where('is_public', true)
            ->whereNotNull('code')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Voucher $voucher) => $voucher->isLive())
            ->map(fn (Voucher $voucher) => $this->present($voucher))
            ->values()
            ->all();
    }

    /** The single offer worth putting in the announcement bar, if any. */
    public function headline(): ?array
    {
        return $this->public()[0] ?? null;
    }

    /**
     * The offer as the strip and pop-up read it.
     *
     * @return array<string,mixed>
     */
    protected function present(Voucher $voucher): array
    {
        $type = match ($voucher->type) {
            Voucher::TYPE_PERCENTAGE => PromotionService::PERCENTAGE,
            Voucher::TYPE_FREE_SHIPPING => PromotionService::FREE_SHIPPING,
            default => PromotionService::FIXED_AMOUNT,
        };

        return [
            'id' => (string) $voucher->id,
            'type' => $type,
            'name' => $voucher->localized('name'),
            'code' => $voucher->code,
            'automatic' => false,
            'discountType' => $voucher->type === Voucher::TYPE_PERCENTAGE ? 'percentage' : 'fixed_amount',
            'discountValue' => $voucher->type === Voucher::TYPE_PERCENTAGE
                ? (float) $voucher->percent
                : (float) Money::fromFils($voucher->amount_fils),
            'xQuantity' => 0,
            'yQuantity' => 0,
            'threshold' => (float) Money::fromFils($voucher->min_subtotal_fils),
            'endDate' => $voucher->ends_at?->toIso8601String(),
            'scope' => ['kind' => 'store', 'label' => __('storefront.promo.scopeStore'), 'items' => []],
            'headline' => match ($type) {
                PromotionService::FREE_SHIPPING => __('storefront.promo.freeShipping'),
                PromotionService::PERCENTAGE => __('storefront.promo.percentOff', [
                    'value' => rtrim(rtrim(number_format((float) $voucher->percent, 2, '.', ''), '0'), '.'),
                ]),
                default => $voucher->localized('name'),
            },
        ];
    }
}
