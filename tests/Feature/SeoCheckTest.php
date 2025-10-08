<?php

namespace Tests\Feature;

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeoCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_seo_check(): void
    {
        $website = Website::factory()->create();

        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('seo_checks', [
            'id' => $seoCheck->id,
            'website_id' => $website->id,
            'status' => 'pending',
        ]);
    }

    public function test_can_dispatch_seo_health_check_job(): void
    {
        Queue::fake();

        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        SeoHealthCheckJob::dispatch($seoCheck);

        Queue::assertPushed(SeoHealthCheckJob::class, function ($job) use ($seoCheck) {
            return $job->seoCheck->id === $seoCheck->id;
        });
    }

    public function test_seo_check_progress_calculation(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'running',
            'total_urls_crawled' => 50,
            'total_crawlable_urls' => 100,
        ]);

        $this->assertEquals(50, $seoCheck->getProgressPercentage());
    }

    public function test_seo_check_health_score_calculation(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 100,
            'computed_errors_count' => 5,
            'computed_http_errors_count' => 10,
        ]);

        // Health score should be: (100 - 15) / 100 * 100 = 85%
        $this->assertEquals(85.0, $seoCheck->getHealthScoreAttribute());
    }
}
