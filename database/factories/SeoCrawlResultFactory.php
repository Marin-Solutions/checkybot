<?php

namespace Database\Factories;

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeoCrawlResultFactory extends Factory
{
    protected $model = SeoCrawlResult::class;

    public function definition(): array
    {
        return [
            'seo_check_id' => SeoCheck::factory(),
            'url' => fake()->url(),
            'status_code' => 200,
            'canonical_url' => null,
            'title' => fake()->sentence(),
            'meta_description' => fake()->paragraph(),
            'h1' => fake()->sentence(),
            'internal_links' => json_encode([
                ['url' => fake()->url(), 'text' => fake()->words(3, true)],
                ['url' => fake()->url(), 'text' => fake()->words(3, true)],
            ]),
            'external_links' => json_encode([
                ['url' => fake()->url(), 'text' => fake()->words(2, true)],
            ]),
            'page_size_bytes' => fake()->numberBetween(10000, 500000),
            'html_size_bytes' => fake()->numberBetween(5000, 200000),
            'html_content' => '<html><body>'.fake()->paragraph().'</body></html>',
            'resource_sizes' => json_encode([
                'css' => fake()->numberBetween(1000, 50000),
                'js' => fake()->numberBetween(5000, 100000),
            ]),
            'headers' => json_encode([
                'content-type' => 'text/html; charset=utf-8',
                'server' => 'nginx',
            ]),
            'response_time_ms' => fake()->numberBetween(100, 3000),
            'robots_txt_allowed' => true,
            'crawl_source' => 'discovery',
            'internal_link_count' => fake()->numberBetween(5, 50),
            'external_link_count' => fake()->numberBetween(0, 10),
            'image_count' => fake()->numberBetween(0, 20),
        ];
    }

    public function withError(): static
    {
        return $this->state(fn (array $attributes) => [
            'status_code' => fake()->randomElement([404, 500, 503]),
        ]);
    }

    public function withRedirect(): static
    {
        return $this->state(fn (array $attributes) => [
            'status_code' => fake()->randomElement([301, 302]),
        ]);
    }

    public function slow(): static
    {
        return $this->state(fn (array $attributes) => [
            'response_time_ms' => fake()->numberBetween(3000, 10000),
        ]);
    }
}
