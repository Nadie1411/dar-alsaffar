<?php

namespace App\Services\Store\Pricing;

/**
 * Everything beyond the basket that decides what an order costs.
 */
final class PricingContext
{
    /**
     * @param  array<int,int>  $addonIds  chosen service add-ons, such as gift wrapping
     * @param  string|null  $phone  who is buying, for per-customer voucher limits when there is no account
     */
    public function __construct(
        public readonly ?int $areaId = null,
        public readonly ?string $voucherCode = null,
        public readonly array $addonIds = [],
        public readonly bool $cashOnDelivery = false,
        public readonly ?int $customerId = null,
        public readonly ?string $phone = null,
    ) {}
}
