<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductQuantityTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductQuantityTier>
 */
class ProductQuantityTierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'min_quantity' => 2,
            'discount_type' => Product::DISCOUNT_NONE,
            'discount_fils' => 0,
            'discount_percent' => 0,
            'free_delivery' => false,
            'label_ar' => null,
            'label_en' => null,
        ];
    }

    public function from(int $quantity): static
    {
        return $this->state(['min_quantity' => $quantity]);
    }

    public function fixed(int $fils): static
    {
        return $this->state(['discount_type' => Product::DISCOUNT_FIXED, 'discount_fils' => $fils]);
    }

    public function percentage(float $percent): static
    {
        return $this->state(['discount_type' => Product::DISCOUNT_PERCENTAGE, 'discount_percent' => $percent]);
    }

    public function withFreeDelivery(): static
    {
        return $this->state(['free_delivery' => true]);
    }
}
