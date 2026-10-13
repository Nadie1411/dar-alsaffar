<?php

namespace Database\Factories;

use App\Models\OptionGroup;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionGroup>
 */
class OptionGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name_ar' => 'اختر',
            'name_en' => 'Choose',
            'layout' => OptionGroup::LAYOUT_RADIO,
            'is_required' => true,
            'min_choices' => 0,
            'max_choices' => 0,
            'sort_order' => 0,
            'is_active' => true,
            'overzaki_id' => null,
        ];
    }

    /** A "choose N" package group: a checkbox layout that takes between $min and $max picks. */
    public function checkbox(int $min, int $max): static
    {
        return $this->state([
            'layout' => OptionGroup::LAYOUT_CHECKBOX,
            'min_choices' => $min,
            'max_choices' => $max,
        ]);
    }

    /** The group a customer builds a set from: exactly $size pieces, all required. */
    public function builder(int $size = 3): static
    {
        return $this->checkbox($size, $size)->state([
            'name_ar' => 'اختياراتك',
            'name_en' => 'Your picks',
            'is_builder' => true,
            'is_required' => true,
        ]);
    }

    public function optional(): static
    {
        return $this->state(['is_required' => false]);
    }
}
