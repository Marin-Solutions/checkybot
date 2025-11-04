<?php

namespace Database\Factories;

use App\Models\NotificationChannels;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationChannelsFactory extends Factory
{
    protected $model = NotificationChannels::class;

    public function definition(): array
    {
        return [
            'title' => fake()->words(2, true),
            'method' => 'POST',
            'url' => fake()->url(),
            'description' => fake()->sentence(),
            'request_body' => json_encode([
                'message' => '{message}',
                'description' => '{description}',
            ]),
            'created_by' => User::factory(),
        ];
    }

    public function slack(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Slack Webhook',
            'url' => 'https://hooks.slack.com/services/XXX/YYY/ZZZ',
            'request_body' => json_encode([
                'text' => '{message}',
            ]),
        ]);
    }

    public function discord(): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => 'Discord Webhook',
            'url' => 'https://discord.com/api/webhooks/XXX/YYY',
            'request_body' => json_encode([
                'content' => '{message}',
            ]),
        ]);
    }
}
