<?php

namespace Database\Factories;

use App\Models\OutboundLink;
use Illuminate\Database\Eloquent\Factories\Factory;

class OutboundLinkFactory extends Factory
{
    protected $model = OutboundLink::class;

    public function definition(): array
    {
        return [
            'website_id' => \App\Models\Website::factory(),
            'found_on' => fake()->url(),
            'outgoing_url' => fake()->url(),
            'http_status_code' => 200,
            'last_checked_at' => now(),
        ];
    }
}
