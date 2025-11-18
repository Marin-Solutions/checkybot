<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServerLogCategory>
 */
class ServerLogCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'name' => fake()->word(),
            'log_directory' => '/var/log/'.fake()->word(),
            'should_collect' => fake()->boolean(),
            'last_collected_at' => null,
        ];
    }
}
