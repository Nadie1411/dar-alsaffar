<?php

namespace App\Services\Store\Pricing;

use App\Models\DeliveryArea;
use App\Models\ServiceAddon;
use App\Support\Money;

/**
 * A basket with every amount worked out, in whole fils. This is the one
 * answer to "what does this order cost" — the cart, the checkout summary and
 * the order that gets created all read it.
 */
final class PricedCart
{
    /**
     * @param  array<int,PricedLine>  $lines
     * @param  array<int,ServiceAddon>  $addons
     * @param  array<int,string>  $problems  reasons the order cannot be placed, other than a line's own
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $subTotalFils,
        public readonly int $discountFils,
        public readonly int $deliveryFeeFils,
        public readonly int $shippingDiscountFils,
        public readonly int $addonsTotalFils,
        public readonly int $codFeeFils,
        public readonly int $minimumOrderFils,
        public readonly int $freeShippingThresholdFils,
        public readonly bool $deliveryResolved,
        public readonly ?DeliveryArea $area,
        public readonly bool $cashOnDeliveryAvailable,
        public readonly VoucherOutcome $voucher,
        public readonly array $addons,
        public readonly array $problems,
    ) {}

    public function totalFils(): int
    {
        return $this->subTotalFils - $this->discountFils
            + $this->deliveryFeeFils + $this->addonsTotalFils + $this->codFeeFils;
    }

    public function totalQuantity(): int
    {
        return array_sum(array_map(fn (PricedLine $line) => $line->available ? $line->quantity : 0, $this->lines));
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function meetsMinimum(): bool
    {
        return $this->minimumOrderFils <= 0 || $this->subTotalFils >= $this->minimumOrderFils;
    }

    /** Whether an order can be placed from this basket as it stands. */
    public function canPlaceOrder(): bool
    {
        return ! $this->isEmpty()
            && $this->problems === []
            && $this->voucher->accepted
            && $this->meetsMinimum()
            && collect($this->lines)->every(fn (PricedLine $line) => $line->available);
    }

    /** @return array<int,string> every reason the basket cannot be ordered, for display */
    public function allProblems(): array
    {
        $lineProblems = collect($this->lines)
            ->filter(fn (PricedLine $line) => ! $line->available && $line->message !== null)
            ->map(fn (PricedLine $line) => $line->message)
            ->all();

        return array_values(array_unique([...$lineProblems, ...$this->problems]));
    }

    /**
     * The document the storefront's cart view model reads, in dinars and in
     * the same field names the previous cart checker used, so the cart,
     * drawer and checkout views needed no changes.
     *
     * @return array<string,mixed>
     */
    public function toQuoteData(): array
    {
        $dinars = fn (int $fils) => Money::fromFils($fils);

        return [
            'symbol' => Money::KWD,
            'items' => array_map(fn (PricedLine $line) => [
                '_id' => $line->key,
                'productId' => $line->productId,
                'quantity' => $line->quantity,
                'unitPrice' => $dinars($line->listUnitPriceFils),
                'unitPriceAfterDiscount' => $dinars($line->unitPriceFils),
                'totalPrice' => $dinars($line->listTotalFils()),
                'totalPriceAfterDiscount' => $dinars($line->totalFils()),
                'symbol' => Money::KWD,
                'status' => $line->available,
                'msg' => $line->message ?? '',
                'isFreeGift' => false,
                'varientId' => null,
                'options' => $line->options,
            ], $this->lines),

            'subTotal' => $dinars($this->subTotalFils),
            'discount' => $dinars($this->discountFils),
            'pointsDiscount' => 0,
            'deliveryFees' => $dinars($this->deliveryFeeFils),
            'vat' => 0,
            'cashOnDeliveryFee' => $dinars($this->codFeeFils),
            'serviceAddonsTotal' => $dinars($this->addonsTotalFils),
            'total' => $dinars($this->totalFils()),
            'amountToPay' => $dinars($this->totalFils()),
            'totalQuantity' => $this->totalQuantity(),
            'minimumOrderAmount' => $dinars($this->minimumOrderFils),

            'location' => ['country' => null, 'city' => $this->area?->delivery_city_id, 'area' => $this->area?->id],
            'isStorePickup' => false,
            'validToCreateOrder' => ! $this->isEmpty()
                && $this->problems === []
                && collect($this->lines)->every(fn (PricedLine $line) => $line->available),
            'availableCashOnDelivery' => $this->cashOnDeliveryAvailable,

            'voucher' => $this->voucher->accepted ? $this->voucher->voucher?->code : null,
            'voucherStatus' => $this->voucher->accepted,
            'voucherMsg' => $this->voucher->message,

            'shippingWaiverAmount' => $dinars($this->freeShippingThresholdFils),
            'shippingDiscountAmount' => $dinars($this->shippingDiscountFils),
            'selectedServiceAddons' => array_map(fn (ServiceAddon $addon) => ['_id' => (string) $addon->id], $this->addons),
            'promotionProgress' => ['hasPromotion' => false],
            'checkerErrors' => array_map(fn (string $problem) => ['msg' => $problem], $this->problems),
            'msg' => '',
        ];
    }
}
