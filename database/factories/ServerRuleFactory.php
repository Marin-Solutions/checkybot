<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerRule;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerRuleFactory extends Factory
{
    protected $model = ServerRule::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'metric' => fake()->randomElement(['cpu_usage', 'ram_usage', 'disk_usage']),
            'operator' => fake()->randomElement(['>', '<', '=']),
            'value' => fake()->numberBetween(50, 95),
            'channel' => fake()->randomElement(['email', 'webhook']),
            'is_active' => true,
        ];
    }

    public function cpuUsage(): static
    {
        return $this->state(fn (array $attributes) => [
            'metric' => 'cpu_usage',
            'operator' => '>',
            'value' => 80,
        ]);
    }

    public function ramUsage(): static
    {
        return $this->state(fn (array $attributes) => [
            'metric' => 'ram_usage',
            'operator' => '>',
            'value' => 90,
        ]);
    }

    public function diskUsage(): static
    {
        return $this->state(fn (array $attributes) => [
            'metric' => 'disk_usage',
            'operator' => '>',
            'value' => 85,
        ]);
    }
}
