<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Server>
 */
class ServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ip' => $this->faker->ipv4(),
            'hostname' => $this->faker->domainName(),
            'enviroment' => $this->faker->linuxProcessor(),
            'description' => $this->faker->text(),
            'created_by' => 1
        ];
    }
}
