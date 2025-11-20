<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Website>
 */
class WebsiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'url' => $this->faker->unique()->url(),
            'description' => $this->faker->text(),
            'created_by' => User::factory(),
            'uptime_check' => 1,
            'uptime_interval' => 1,
            'ssl_check' => 1,
        ];
    }
}
