<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectComponent>
 */
class ProjectComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->word(),
            'summary' => fake()->sentence(),
            'source' => 'package',
            'declared_interval' => '5m',
            'interval_minutes' => 5,
            'current_status' => fake()->randomElement(['healthy', 'warning', 'danger']),
            'last_reported_status' => 'healthy',
            'metrics' => ['value' => fake()->numberBetween(1, 100)],
            'is_archived' => false,
            'project_paused_monitoring' => false,
            'archived_at' => null,
            'archive_reason' => null,
            'silenced_until' => null,
            'created_by' => User::factory(),
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => [
            'is_archived' => true,
            'archived_at' => now()->subHour(),
            'archive_reason' => \App\Models\ProjectComponent::ARCHIVE_REASON_USER,
        ]);
    }
}
