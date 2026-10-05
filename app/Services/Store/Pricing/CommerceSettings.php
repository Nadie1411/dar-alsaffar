<?php

namespace App\Services\Store\Pricing;

use App\Services\Settings;

/**
 * The store-wide numbers checkout works from: what delivery costs by default,
 * when it is free, the smallest order accepted and the cash-on-delivery fee.
 * Set in the admin panel; config/store.php supplies them until then.
 */
class CommerceSettings
{
    public function __construct(protected Settings $settings) {}

    public function deliveryFeeFils(): int
    {
        return $this->fils('commerce.delivery_fee_fils');
    }

    /** Subtotal at which delivery becomes free. Zero means there is no such offer. */
    public function freeShippingThresholdFils(): int
    {
        return $this->fils('commerce.free_shipping_fils');
    }

    public function minimumOrderFils(): int
    {
        return $this->fils('commerce.minimum_order_fils');
    }

    public function codFeeFils(): int
    {
        return $this->fils('commerce.cod_fee_fils');
    }

    /** The shop-wide switch for cash on delivery. */
    public function cashOnDeliveryEnabled(): bool
    {
        return $this->settings->bool('checkout.cod', true);
    }

    protected function fils(string $key): int
    {
        return max($this->settings->int($key, (int) config('store.'.$key)), 0);
    }
}
