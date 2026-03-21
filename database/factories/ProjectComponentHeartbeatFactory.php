<?php

namespace Database\Factories;

use App\Models\ProjectComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectComponentHeartbeat>
 */
class ProjectComponentHeartbeatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_component_id' => ProjectComponent::factory(),
            'component_name' => fake()->word(),
            'status' => fake()->randomElement(['healthy', 'warning', 'danger']),
            'event' => 'heartbeat',
            'summary' => fake()->sentence(),
            'metrics' => ['value' => fake()->numberBetween(1, 100)],
            'observed_at' => now(),
        ];
    }
}
