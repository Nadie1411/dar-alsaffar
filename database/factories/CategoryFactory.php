<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'parent_id' => null,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(100, 9999),
            'name_ar' => 'فئة '.$name,
            'name_en' => Str::title($name),
            'image' => null,
            'icon' => null,
            'sort_order' => fake()->numberBetween(0, 20),
            'is_featured' => false,
            'is_active' => true,
            'overzaki_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function childOf(Category $parent): static
    {
        return $this->state(['parent_id' => $parent->id]);
    }
}
