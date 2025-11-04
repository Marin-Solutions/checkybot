<?php

namespace Tests\Unit\Models;

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Models\Website;
use Tests\TestCase;

class SeoCheckTest extends TestCase
{
    public function test_seo_check_belongs_to_website(): void
    {
        $website = Website::factory()->create();
        $check = SeoCheck::factory()->create(['website_id' => $website->id]);

        $this->assertInstanceOf(Website::class, $check->website);
        $this->assertEquals($website->id, $check->website->id);
    }

    public function test_seo_check_has_many_crawl_results(): void
    {
        $check = SeoCheck::factory()->create();
        SeoCrawlResult::factory()->count(10)->create(['seo_check_id' => $check->id]);

        $this->assertCount(10, $check->crawlResults);
        $this->assertInstanceOf(SeoCrawlResult::class, $check->crawlResults->first());
    }

    public function test_seo_check_has_many_seo_issues(): void
    {
        $check = SeoCheck::factory()->create();
        SeoIssue::factory()->count(5)->create(['seo_check_id' => $check->id]);

        $this->assertCount(5, $check->seoIssues);
        $this->assertInstanceOf(SeoIssue::class, $check->seoIssues->first());
    }

    public function test_seo_check_is_completed_status_method(): void
    {
        $check = SeoCheck::factory()->completed()->create();

        $this->assertTrue($check->isCompleted());
        $this->assertFalse($check->isRunning());
        $this->assertFalse($check->isFailed());
        $this->assertFalse($check->isPending());
    }

    public function test_seo_check_is_running_status_method(): void
    {
        $check = SeoCheck::factory()->running()->create();

        $this->assertTrue($check->isRunning());
        $this->assertFalse($check->isCompleted());
        $this->assertFalse($check->isFailed());
        $this->assertFalse($check->isPending());
    }

    public function test_seo_check_is_failed_status_method(): void
    {
        $check = SeoCheck::factory()->failed()->create();

        $this->assertTrue($check->isFailed());
        $this->assertFalse($check->isCompleted());
        $this->assertFalse($check->isRunning());
        $this->assertFalse($check->isPending());
    }

    public function test_seo_check_is_pending_status_method(): void
    {
        $check = SeoCheck::factory()->create(['status' => 'pending']);

        $this->assertTrue($check->isPending());
        $this->assertFalse($check->isCompleted());
        $this->assertFalse($check->isRunning());
        $this->assertFalse($check->isFailed());
    }

    public function test_seo_check_is_cancellable_when_running(): void
    {
        $check = SeoCheck::factory()->running()->create();

        $this->assertTrue($check->isCancellable());
    }

    public function test_seo_check_is_not_cancellable_when_completed(): void
    {
        $check = SeoCheck::factory()->completed()->create();

        $this->assertFalse($check->isCancellable());
    }

    public function test_seo_check_progress_defaults_to_zero(): void
    {
        $check = SeoCheck::factory()->create();

        $this->assertEquals(0, $check->progress);
    }

    public function test_seo_check_tracks_urls_crawled(): void
    {
        $check = SeoCheck::factory()->running()->create([
            'total_urls_crawled' => 50,
            'total_crawlable_urls' => 100,
        ]);

        $this->assertEquals(50, $check->total_urls_crawled);
        $this->assertEquals(100, $check->total_crawlable_urls);
    }
}
