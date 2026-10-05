<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 9999),
            'sku' => null,
            'name_ar' => 'عطر '.$name,
            'name_en' => Str::title($name),
            'description_ar' => '<p>وصف المنتج</p>',
            'description_en' => '<p>Product description</p>',
            'sell_price_fils' => fake()->numberBetween(5, 60) * 1000,
            'discount_type' => Product::DISCOUNT_NONE,
            'discount_fils' => 0,
            'discount_percent' => 0,
            'discount_starts_at' => null,
            'discount_ends_at' => null,
            'track_stock' => false,
            'stock' => 0,
            'low_stock_threshold' => 0,
            'max_per_order' => null,
            'main_image' => null,
            'video' => null,
            'tags' => null,
            'is_active' => true,
            'is_featured' => false,
            'is_new' => false,
            'is_popular' => false,
            'cod_enabled' => true,
            'sort_order' => fake()->numberBetween(0, 50),
            'sales_count' => 0,
            'rating_average' => 0,
            'rating_count' => 0,
            'overzaki_id' => null,
        ];
    }

    public function priced(int $fils): static
    {
        return $this->state(['sell_price_fils' => $fils]);
    }

    public function fixedDiscount(int $fils): static
    {
        return $this->state(['discount_type' => Product::DISCOUNT_FIXED, 'discount_fils' => $fils]);
    }

    public function percentDiscount(float $percent): static
    {
        return $this->state(['discount_type' => Product::DISCOUNT_PERCENTAGE, 'discount_percent' => $percent]);
    }

    public function discountWindow(?string $starts, ?string $ends): static
    {
        return $this->state(['discount_starts_at' => $starts, 'discount_ends_at' => $ends]);
    }

    public function withStock(int $stock, int $lowAt = 2): static
    {
        return $this->state(['track_stock' => true, 'stock' => $stock, 'low_stock_threshold' => $lowAt]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
