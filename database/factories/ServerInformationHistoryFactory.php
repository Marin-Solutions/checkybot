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
            'ram_user' => $this->faker->numberBetween(10, 100),
            'disk_use' => $this->faker->numberBetween(10, 100),
            'cpu_load' => $this->faker->randomFloat(2, 0, 100),
            'server_id' =>  $this->faker->numberBetween(1, 10)
        ];
    }
}
