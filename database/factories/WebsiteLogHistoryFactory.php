<?php

namespace Database\Factories;

use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Support\UptimeTransportError;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebsiteLogHistoryFactory extends Factory
{
    protected $model = WebsiteLogHistory::class;

    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'http_status_code' => 200,
            'speed' => fake()->numberBetween(100, 1000),
            'status' => 'healthy',
            'summary' => 'Heartbeat received successfully.',
            'transport_error_type' => null,
            'transport_error_message' => null,
            'transport_error_code' => null,
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
            'speed' => fake()->numberBetween(3000, 10000),
        ]);
    }

    public function transportError(string $type = 'connection'): static
    {
        return $this->state(fn (array $attributes) => [
            'http_status_code' => 0,
            'status' => 'danger',
            'summary' => UptimeTransportError::summary($type),
            'transport_error_type' => $type,
            'transport_error_message' => 'cURL error 7: Failed to connect to example.com port 443.',
            'transport_error_code' => 7,
        ]);
    }
}
