<?php

namespace Tests\Feature;

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\Website;
use App\Services\SeoIssueDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoIssueDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_detection_creates_issues(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 1,
        ]);

        // Create a crawl result with HTML content that should trigger issues
        $crawlResult = SeoCrawlResult::create([
            'seo_check_id' => $seoCheck->id,
            'url' => $website->url,
            'status_code' => 200,
            'title' => '', // Missing title should trigger an issue
            'meta_description' => '', // Missing meta description should trigger an issue
            'h1' => '', // Missing H1 should trigger an issue
            'html_content' => '<html><head></head><body><p>Test content</p></body></html>',
            'internal_links' => [],
            'external_links' => [],
            'page_size_bytes' => 100,
            'html_size_bytes' => 100,
            'response_time_ms' => 500,
            'internal_link_count' => 0,
            'external_link_count' => 0,
            'image_count' => 0,
            'robots_txt_allowed' => true,
            'crawl_source' => 'discovery',
        ]);

        $issueDetectionService = new SeoIssueDetectionService;
        $issueDetectionService->detectIssues($seoCheck);

        // Check that issues were created
        $this->assertGreaterThan(0, $seoCheck->seoIssues()->count());

        // Check for specific issues
        $this->assertTrue($seoCheck->seoIssues()->where('type', 'missing_title')->exists());
        $this->assertTrue($seoCheck->seoIssues()->where('type', 'missing_meta_description')->exists());
        $this->assertTrue($seoCheck->seoIssues()->where('type', 'missing_h1')->exists());
    }
}
