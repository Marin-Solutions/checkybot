<?php

namespace Database\Factories;

use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeoCheckFactory extends Factory
{
    protected $model = SeoCheck::class;

    public function definition(): array
    {
        return [
            'website_id' => Website::factory(),
            'status' => 'pending',
            'progress' => 0,
            'total_urls_crawled' => 0,
            'total_crawlable_urls' => 0,
            'sitemap_used' => false,
            'robots_txt_checked' => false,
            'started_at' => null,
            'finished_at' => null,
            'crawl_summary' => null,
        ];
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
            'started_at' => now()->subMinutes(5),
            'progress' => fake()->numberBetween(10, 90),
            'total_urls_crawled' => fake()->numberBetween(10, 100),
            'total_crawlable_urls' => fake()->numberBetween(50, 200),
        ]);
    }

    public function completed(): static
    {
        $totalUrls = fake()->numberBetween(50, 200);

        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'finished_at' => now()->subMinutes(10),
            'progress' => 100,
            'total_urls_crawled' => $totalUrls,
            'total_crawlable_urls' => $totalUrls,
            'sitemap_used' => true,
            'robots_txt_checked' => true,
            'crawl_summary' => json_encode([
                'crawl_strategy' => 'sitemap_preload',
                'sitemap_urls_found' => true,
                'robots_txt_checked' => true,
            ]),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'started_at' => now()->subMinutes(30),
            'finished_at' => now()->subMinutes(15),
            'progress' => fake()->numberBetween(10, 50),
        ]);
    }
}
