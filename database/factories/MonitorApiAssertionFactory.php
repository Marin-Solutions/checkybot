<?php

namespace Database\Factories;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use Illuminate\Database\Eloquent\Factories\Factory;

class MonitorApiAssertionFactory extends Factory
{
    protected $model = MonitorApiAssertion::class;

    public function definition(): array
    {
        return [
            'monitor_api_id' => MonitorApis::factory(),
            'path' => 'data.status',
            'expected_value' => 'success',
            'sort_order' => fake()->numberBetween(1, 10),
        ];
    }

    public function forMonitor(MonitorApis $monitor): static
    {
        return $this->state(fn (array $attributes) => [
            'monitor_api_id' => $monitor->id,
        ]);
    }
}
