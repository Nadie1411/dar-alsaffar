<?php

namespace App\Services\Store\Pricing;

use App\Models\Product;

/**
 * One basket line, priced.
 */
final class PricedLine
{
    /**
     * @param  array<int,array<string,mixed>>  $options  the choices made, resolved against the product's own options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $productId,
        public readonly ?Product $product,
        public readonly int $quantity,
        /** The price of one unit before the product's own discount, options included. */
        public readonly int $listUnitPriceFils,
        /** The price of one unit as charged: after the product's discount, options included. */
        public readonly int $unitPriceFils,
        public readonly bool $available,
        public readonly ?string $message,
        public readonly array $options = [],
        /** What a quantity tier takes off this line, once rather than per unit. */
        public readonly int $quantityDiscountFils = 0,
        /** Whether the quantity tier this line reached waives delivery. */
        public readonly bool $freeDelivery = false,
    ) {}

    /** What the line costs after any quantity-tier discount. */
    public function totalFils(): int
    {
        return $this->unitPriceFils * $this->quantity - $this->quantityDiscountFils;
    }

    public function listTotalFils(): int
    {
        return $this->listUnitPriceFils * $this->quantity;
    }
}
