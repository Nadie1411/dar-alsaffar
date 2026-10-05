<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAddress>
 */
class CustomerAddressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'delivery_city_id' => null,
            'delivery_area_id' => DeliveryArea::factory(),
            'block' => (string) fake()->numberBetween(1, 12),
            'street' => fake()->streetName(),
            'avenue' => null,
            'building' => (string) fake()->numberBetween(1, 99),
            'floor' => null,
            'apartment' => null,
        ];
    }
}
