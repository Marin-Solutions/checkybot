<?php

namespace Database\Factories;

use App\Models\SeoSchedule;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeoScheduleFactory extends Factory
{
    protected $model = SeoSchedule::class;

    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'created_by' => User::factory(),
            'frequency' => fake()->randomElement(['daily', 'weekly', 'monthly']),
            'schedule_time' => '09:00',
            'schedule_day' => null,
            'last_run_at' => null,
            'next_run_at' => now()->addDay(),
            'is_active' => true,
        ];
    }

    public function daily(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'daily',
            'schedule_day' => null,
        ]);
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'weekly',
            'schedule_day' => 'monday',
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'monthly',
            'schedule_day' => 1,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
