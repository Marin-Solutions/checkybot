<?php

namespace Tests\Unit\Commands;

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PopulateSeoCheckComputedColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_can_be_executed(): void
    {
        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful()
            ->assertExitCode(0);
    }

    public function test_command_populates_errors_count(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 10,
        ]);

        SeoIssue::create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com',
            'severity' => 'error',
            'type' => 'missing_title',
            'title' => 'Missing title',
            'description' => 'Page is missing title tag',
        ]);

        SeoIssue::create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/about',
            'severity' => 'error',
            'type' => 'missing_description',
            'title' => 'Missing description',
            'description' => 'Page is missing meta description',
        ]);

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();

        $seoCheck->refresh();
        $this->assertEquals(2, $seoCheck->computed_errors_count);
    }

    public function test_command_populates_warnings_count(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 10,
        ]);

        SeoIssue::create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com',
            'severity' => 'warning',
            'type' => 'title_too_long',
            'title' => 'Title too long',
            'description' => 'Page title exceeds recommended length',
        ]);

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();

        $seoCheck->refresh();
        $this->assertEquals(1, $seoCheck->computed_warnings_count);
    }

    public function test_command_populates_notices_count(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 10,
        ]);

        SeoIssue::create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com',
            'severity' => 'notice',
            'type' => 'image_missing_alt',
            'title' => 'Image missing alt',
            'description' => 'Image is missing alt attribute',
        ]);

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();

        $seoCheck->refresh();
        $this->assertEquals(1, $seoCheck->computed_notices_count);
    }

    public function test_command_populates_http_errors_count(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 10,
        ]);

        SeoCrawlResult::create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/404',
            'status_code' => 404,
        ]);

        SeoCrawlResult::create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/500',
            'status_code' => 500,
        ]);

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();

        $seoCheck->refresh();
        $this->assertEquals(2, $seoCheck->computed_http_errors_count);
    }

    public function test_command_calculates_health_score(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 10,
        ]);

        // Create 2 errors, so 8 out of 10 URLs are healthy
        SeoIssue::create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com',
            'severity' => 'error',
            'type' => 'missing_title',
            'title' => 'Missing title',
            'description' => 'Page is missing title tag',
        ]);

        SeoIssue::create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/about',
            'severity' => 'error',
            'type' => 'missing_description',
            'title' => 'Missing description',
            'description' => 'Page is missing meta description',
        ]);

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();

        $seoCheck->refresh();
        $this->assertEquals(80.0, $seoCheck->computed_health_score);
    }

    public function test_command_calculates_health_score_with_http_errors(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 10,
        ]);

        SeoCrawlResult::create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/404',
            'status_code' => 404,
        ]);

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();

        $seoCheck->refresh();
        $this->assertEquals(90.0, $seoCheck->computed_health_score);
    }

    public function test_command_handles_zero_urls_crawled(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
            'total_urls_crawled' => 0,
        ]);

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();

        $seoCheck->refresh();
        $this->assertEquals(0.0, $seoCheck->computed_health_score);
    }

    public function test_command_ensures_health_score_not_negative(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 2,
        ]);

        // Create more errors than URLs crawled (edge case)
        for ($i = 0; $i < 5; $i++) {
            SeoIssue::create([
                'seo_check_id' => $seoCheck->id,
                'url' => "https://example.com/page{$i}",
                'severity' => 'error',
                'type' => 'missing_title',
                'title' => 'Missing title',
                'description' => 'Page is missing title tag',
            ]);
        }

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();

        $seoCheck->refresh();
        $this->assertGreaterThanOrEqual(0.0, $seoCheck->computed_health_score);
    }

    public function test_command_processes_multiple_seo_checks(): void
    {
        $website = Website::factory()->create();
        $seoChecks = [];

        for ($i = 0; $i < 3; $i++) {
            $seoChecks[] = SeoCheck::create([
                'website_id' => $website->id,
                'status' => 'completed',
                'total_urls_crawled' => 10,
            ]);
        }

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();

        foreach ($seoChecks as $seoCheck) {
            $seoCheck->refresh();
            $this->assertNotNull($seoCheck->computed_health_score);
        }
    }

    public function test_command_displays_progress_bar(): void
    {
        $website = Website::factory()->create();
        SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 10,
        ]);

        $this->artisan('seo:populate-computed-columns')
            ->expectsOutput('Starting to populate computed columns for SEO checks...')
            ->assertSuccessful();
    }

    public function test_command_displays_completion_message(): void
    {
        $website = Website::factory()->create();
        SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 10,
        ]);

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();
    }

    public function test_command_handles_no_seo_checks(): void
    {
        $this->artisan('seo:populate-computed-columns')
            ->expectsOutput('Successfully populated computed columns for 0 SEO checks!')
            ->assertSuccessful();
    }

    public function test_command_updates_existing_computed_values(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 10,
            'computed_errors_count' => 999,
            'computed_health_score' => 0.0,
        ]);

        SeoIssue::create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com',
            'severity' => 'error',
            'type' => 'missing_title',
            'title' => 'Missing title',
            'description' => 'Page is missing title tag',
        ]);

        $this->artisan('seo:populate-computed-columns')
            ->assertSuccessful();

        $seoCheck->refresh();
        $this->assertEquals(1, $seoCheck->computed_errors_count);
        $this->assertEquals(90.0, $seoCheck->computed_health_score);
    }
}
