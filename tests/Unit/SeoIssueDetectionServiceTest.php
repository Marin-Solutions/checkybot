<?php

namespace Tests\Unit;

use App\Enums\SeoIssueSeverity;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Models\Website;
use App\Services\SeoIssueDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoIssueDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SeoIssueDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeoIssueDetectionService();
    }

    public function test_detects_missing_title_as_error(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::factory()->create(['website_id' => $website->id]);

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'title' => null,
            'status_code' => 200,
        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'missing_title',
            'severity' => SeoIssueSeverity::Error->value,
            'url' => 'https://example.com/page1',
        ]);
    }

    public function test_detects_missing_meta_description_as_warning(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::factory()->create(['website_id' => $website->id]);

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'meta_description' => null,
            'status_code' => 200,
        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'missing_meta_description',
            'severity' => SeoIssueSeverity::Warning->value,
            'url' => 'https://example.com/page1',
        ]);
    }

    public function test_detects_slow_response_as_warning(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::factory()->create(['website_id' => $website->id]);

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'response_time_ms' => 1500, // > 1 second
            'status_code' => 200,
        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'slow_response',
            'severity' => SeoIssueSeverity::Warning->value,
            'url' => 'https://example.com/page1',
        ]);
    }

    public function test_detects_duplicate_titles_as_error(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::factory()->create(['website_id' => $website->id]);

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'title' => 'Same Title',
            'status_code' => 200,
        ]);

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page2',
            'title' => 'Same Title',
            'status_code' => 200,
        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseCount('seo_issues', 2);
        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'duplicate_title',
            'severity' => SeoIssueSeverity::Error->value,
        ]);
    }

    public function test_detects_broken_internal_links_as_error(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::factory()->create(['website_id' => $website->id]);

        // Create a page with a broken internal link
        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'internal_links' => [
                ['url' => 'https://example.com/broken-page', 'text' => 'Broken Link']
            ],
            'status_code' => 200,
        ]);

        // Create the broken page
        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/broken-page',
            'status_code' => 404,
        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'broken_internal_link',
            'severity' => SeoIssueSeverity::Error->value,
            'url' => 'https://example.com/page1',
        ]);
    }
}
