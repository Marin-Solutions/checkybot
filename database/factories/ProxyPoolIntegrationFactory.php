<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProxyPoolIntegration>
 */
class ProxyPoolIntegrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'project_component_id' => null,
            'created_by' => User::factory(),
            'name' => fake()->company().' Proxy Pool',
            'base_url' => 'https://proxy-'.Str::lower(Str::random(8)).'.test',
            'token' => Str::random(48),
            'check_interval' => '5m',
            'is_active' => true,
            'last_sync_status' => null,
            'last_sync_error' => null,
            'last_synced_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => [
            'is_active' => false,
        ]);
    }
}
