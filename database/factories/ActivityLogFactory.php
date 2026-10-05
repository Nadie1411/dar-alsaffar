<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'action' => 'order.status_changed',
            'subject_type' => 'order',
            'subject_id' => fake()->numberBetween(1, 500),
            'subject_label' => 'DS-'.fake()->numberBetween(100001, 100500),
            'properties' => null,
            'ip' => fake()->ipv4(),
        ];
    }
}
