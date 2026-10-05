<?php

namespace Database\Factories;

use App\Models\DeliveryArea;
use App\Models\DeliveryCity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryArea>
 */
class DeliveryAreaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->streetName();

        return [
            'delivery_city_id' => DeliveryCity::factory(),
            'name_ar' => 'منطقة '.$name,
            'name_en' => $name,
            'fee_fils' => null,
            'sort_order' => 0,
            'is_active' => true,
            'overzaki_id' => null,
        ];
    }

    public function fee(int $fils): static
    {
        return $this->state(['fee_fils' => $fils]);
    }
}
