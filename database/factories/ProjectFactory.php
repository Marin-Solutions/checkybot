<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'group' => fake()->optional()->word(),
            'environment' => fake()->randomElement(['production', 'staging', 'development']),
            'technology' => fake()->randomElement(['Laravel', 'React', 'Vue', 'Node.js']),
            'token' => fake()->unique()->sha256(),
            'created_by' => \App\Models\User::factory(),
        ];
    }
}
