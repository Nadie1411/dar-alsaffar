<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unit = fake()->numberBetween(5, 40) * 1000;
        $quantity = fake()->numberBetween(1, 3);

        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'name_ar' => 'عطر',
            'name_en' => 'Perfume',
            'sku' => null,
            'image' => null,
            'quantity' => $quantity,
            'unit_price_fils' => $unit,
            'total_fils' => $unit * $quantity,
            'options' => null,
            'is_gift' => false,
        ];
    }
}
