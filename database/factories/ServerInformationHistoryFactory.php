<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServerInformationHistory>
 */
class ServerInformationHistoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_id' => \App\Models\Server::factory(),
            'cpu_load' => $this->faker->randomFloat(2, 0, 100),
            'ram_free_percentage' => $this->faker->numberBetween(10, 90),
            'ram_free' => $this->faker->numberBetween(1000, 16000),
            'disk_free_percentage' => $this->faker->numberBetween(10, 90),
            'disk_free_bytes' => $this->faker->numberBetween(10000, 500000),
        ];
    }
}
