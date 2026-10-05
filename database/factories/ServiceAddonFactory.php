<?php

namespace Database\Factories;

use App\Models\ServiceAddon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceAddon>
 */
class ServiceAddonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name_ar' => 'تغليف هدية',
            'name_en' => 'Gift wrapping',
            'description_ar' => null,
            'description_en' => null,
            'price_fils' => 1500,
            'image' => null,
            'sort_order' => 0,
            'is_active' => true,
            'overzaki_id' => null,
        ];
    }
}
