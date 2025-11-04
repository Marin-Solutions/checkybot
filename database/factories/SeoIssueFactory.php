<?php

namespace Database\Factories;

use App\Enums\SeoIssueSeverity;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeoIssueFactory extends Factory
{
    protected $model = SeoIssue::class;

    public function definition(): array
    {
        return [
            'seo_check_id' => SeoCheck::factory(),
            'seo_crawl_result_id' => SeoCrawlResult::factory(),
            'type' => 'missing_title',
            'severity' => SeoIssueSeverity::Error,
            'url' => fake()->url(),
            'title' => 'Missing Page Title',
            'description' => 'This page is missing a title tag',
            'data' => null,
        ];
    }

    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => SeoIssueSeverity::Error,
            'type' => fake()->randomElement([
                'missing_title',
                'broken_internal_link',
                'canonical_error',
                'mixed_content',
            ]),
        ]);
    }

    public function warning(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => SeoIssueSeverity::Warning,
            'type' => fake()->randomElement([
                'missing_meta_description',
                'missing_h1',
                'slow_response',
            ]),
        ]);
    }

    public function notice(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => SeoIssueSeverity::Notice,
            'type' => fake()->randomElement([
                'missing_alt_text',
                'title_too_short',
                'too_few_internal_links',
            ]),
        ]);
    }
}
