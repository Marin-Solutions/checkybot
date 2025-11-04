<?php

namespace Database\Factories;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonitorApiResultFactory extends Factory
{
    protected $model = MonitorApiResult::class;

    public function definition(): array
    {
        $isSuccess = fake()->boolean(80);

        return [
            'monitor_api_id' => MonitorApis::factory(),
            'is_success' => $isSuccess,
            'response_time_ms' => fake()->numberBetween(50, 2000),
            'http_code' => $isSuccess ? 200 : fake()->randomElement([400, 404, 500, 503]),
            'failed_assertions' => $isSuccess ? null : json_encode(['data.status' => 'Expected: success, Got: error']),
            'response_body' => json_encode(['data' => ['status' => $isSuccess ? 'success' : 'error']]),
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_success' => true,
            'http_code' => 200,
            'failed_assertions' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_success' => false,
            'http_code' => fake()->randomElement([400, 404, 500, 503]),
            'failed_assertions' => json_encode(['error' => 'Test failed']),
        ]);
    }
}
