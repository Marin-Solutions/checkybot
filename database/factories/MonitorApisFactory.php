<?php

namespace Database\Factories;

use App\Models\MonitorApis;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonitorApisFactory extends Factory
{
    protected $model = MonitorApis::class;

    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'url' => fake()->url(),
            'data_path' => 'data.results',
            'headers' => json_encode([
                'Accept' => 'application/json',
                'User-Agent' => 'CheckyBot/1.0',
            ]),
            'created_by' => User::factory(),
        ];
    }

    public function withCustomHeaders(array $headers): static
    {
        return $this->state(fn (array $attributes) => [
            'headers' => json_encode($headers),
        ]);
    }

    public function withDataPath(string $path): static
    {
        return $this->state(fn (array $attributes) => [
            'data_path' => $path,
        ]);
    }
}
