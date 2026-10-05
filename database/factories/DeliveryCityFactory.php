<?php

namespace Database\Factories;

use App\Models\DeliveryCity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryCity>
 */
class DeliveryCityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'name_ar' => 'محافظة '.$name,
            'name_en' => $name,
            'sort_order' => 0,
            'is_active' => true,
            'overzaki_id' => null,
        ];
    }
}
