<?php

namespace Database\Factories;

use App\Models\OptionGroup;
use App\Models\OptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionValue>
 */
class OptionValueFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'option_group_id' => OptionGroup::factory(),
            'name_ar' => 'خيار '.$name,
            'name_en' => ucfirst($name),
            'price_fils' => 0,
            'image' => null,
            'sort_order' => 0,
            'is_active' => true,
            'overzaki_id' => null,
        ];
    }

    public function priced(int $fils): static
    {
        return $this->state(['price_fils' => $fils]);
    }
}
