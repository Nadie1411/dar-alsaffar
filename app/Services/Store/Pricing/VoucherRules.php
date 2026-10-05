<?php

namespace App\Services\Store\Pricing;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Voucher;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides whether a voucher code applies to a basket and what it takes off.
 */
class VoucherRules
{
    public function evaluate(?string $code, int $subTotalFils, PricingContext $context): VoucherOutcome
    {
        $code = Voucher::normaliseCode((string) $code);

        if ($code === '') {
            return VoucherOutcome::none();
        }

        $voucher = Voucher::query()->where('code', $code)->first();

        if ($voucher === null || ! $voucher->is_active) {
            return VoucherOutcome::rejected(__('storefront.voucher.notFound'));
        }

        $now = now();

        if ($voucher->starts_at !== null && $now->lt($voucher->starts_at)) {
            return VoucherOutcome::rejected(__('storefront.voucher.notStarted'));
        }

        if ($voucher->ends_at !== null && $now->gt($voucher->ends_at)) {
            return VoucherOutcome::rejected(__('storefront.voucher.expired'));
        }

        if ($subTotalFils < $voucher->min_subtotal_fils) {
            return VoucherOutcome::rejected(__('storefront.voucher.minimum', [
                'amount' => Money::format(Money::fromFils($voucher->min_subtotal_fils), Money::KWD),
            ]));
        }

        if ($voucher->usage_limit !== null && $this->redemptions($voucher)->count() >= $voucher->usage_limit) {
            return VoucherOutcome::rejected(__('storefront.voucher.usedUp'));
        }

        if ($voucher->usage_limit_per_customer !== null
            && $this->redemptionsBy($voucher, $context)->count() >= $voucher->usage_limit_per_customer) {
            return VoucherOutcome::rejected(__('storefront.voucher.alreadyUsed'));
        }

        return new VoucherOutcome(
            $voucher,
            true,
            null,
            $this->discount($voucher, $subTotalFils),
            $voucher->type === Voucher::TYPE_FREE_SHIPPING,
        );
    }

    /** What the voucher takes off the subtotal, never more than the subtotal itself. */
    protected function discount(Voucher $voucher, int $subTotalFils): int
    {
        $discount = match ($voucher->type) {
            Voucher::TYPE_FIXED => (int) $voucher->amount_fils,
            // Percentage in basis points, rounded half up to a whole fils.
            Voucher::TYPE_PERCENTAGE => intdiv($subTotalFils * (int) round((float) $voucher->percent * 100) + 5000, 10000),
            default => 0,
        };

        if ($voucher->max_discount_fils !== null) {
            $discount = min($discount, $voucher->max_discount_fils);
        }

        return min($discount, $subTotalFils);
    }

    /** Orders that used the code and were not cancelled. */
    protected function redemptions(Voucher $voucher): Builder
    {
        return Order::query()
            ->where('voucher_code', $voucher->code)
            ->where('status', '!=', OrderStatus::Cancelled->value);
    }

    /**
     * The same, for the shopper in question — an account, or the phone number
     * given at checkout. With neither known there is nothing to count; the
     * limit is enforced again once the order is placed and the phone is known.
     */
    protected function redemptionsBy(Voucher $voucher, PricingContext $context): Builder
    {
        $query = $this->redemptions($voucher);

        if ($context->customerId === null && $context->phone === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($context): void {
            if ($context->customerId !== null) {
                $query->orWhere('customer_id', $context->customerId);
            }

            if ($context->phone !== null) {
                $query->orWhere('customer_phone', $context->phone);
            }
        });
    }
}
