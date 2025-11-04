<?php

namespace Database\Factories;

use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebsiteLogHistoryFactory extends Factory
{
    protected $model = WebsiteLogHistory::class;

    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'http_status_code' => 200,
            'response_time_ms' => fake()->numberBetween(100, 1000),
        ];
    }

    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'http_status_code' => fake()->randomElement([404, 500, 503]),
        ]);
    }

    public function slow(): static
    {
        return $this->state(fn (array $attributes) => [
            'response_time_ms' => fake()->numberBetween(3000, 10000),
        ]);
    }
}
