<?php

namespace Tests\Unit\Services;

use App\Enums\SeoIssueSeverity;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Services\SeoIssueDetectionService;
use Tests\TestCase;

class SeoIssueDetectionServiceTest extends TestCase
{
    protected SeoIssueDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeoIssueDetectionService;
    }

    public function test_detects_missing_title_issues(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'title' => null,
            'html_content' => '<html><body>Content without title</body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'missing_title',
            'severity' => SeoIssueSeverity::Error->value,
        ]);
    }

    public function test_detects_title_too_short_issues(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'title' => 'Short',
            'html_content' => '<html><head><title>Short</title></head><body>Content</body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'title_too_short',
            'severity' => SeoIssueSeverity::Notice->value,
        ]);
    }

    public function test_detects_title_too_long_issues(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        $longTitle = str_repeat('A', 65);
        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'title' => $longTitle,
            'html_content' => "<html><head><title>{$longTitle}</title></head><body>Content</body></html>",

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'title_too_long',
            'severity' => SeoIssueSeverity::Notice->value,
        ]);
    }

    public function test_detects_missing_meta_description(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'meta_description' => null,
            'html_content' => '<html><head><title>Page</title></head><body>Content</body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'missing_meta_description',
            'severity' => SeoIssueSeverity::Warning->value,
        ]);
    }

    public function test_detects_missing_h1_tag(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'h1' => null,
            'html_content' => '<html><head><title>Page</title></head><body><p>Content without H1</p></body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'missing_h1',
            'severity' => SeoIssueSeverity::Warning->value,
        ]);
    }

    public function test_detects_multiple_h1_tags(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'html_content' => '<html><head><title>Page</title></head><body><h1>First</h1><h1>Second</h1></body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'duplicate_h1',
            'severity' => SeoIssueSeverity::Warning->value,
        ]);
    }

    public function test_detects_slow_response_time(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'response_time_ms' => 1500,
            'html_content' => '<html><head><title>Page</title></head><body>Content</body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'slow_response',
            'severity' => SeoIssueSeverity::Warning->value,
        ]);
    }

    public function test_detects_duplicate_titles(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'title' => 'Duplicate Title',
            'html_content' => '<html><head><title>Duplicate Title</title></head><body>Content</body></html>',

        ]);

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page2',
            'status_code' => 200,
            'title' => 'Duplicate Title',
            'html_content' => '<html><head><title>Duplicate Title</title></head><body>Content</body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $issues = SeoIssue::where('seo_check_id', $seoCheck->id)
            ->where('type', 'duplicate_title')
            ->get();

        $this->assertCount(2, $issues);
    }

    public function test_detects_duplicate_meta_descriptions(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'meta_description' => 'Same description',
            'html_content' => '<html><head><meta name="description" content="Same description"></head><body>Content</body></html>',

        ]);

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page2',
            'status_code' => 200,
            'meta_description' => 'Same description',
            'html_content' => '<html><head><meta name="description" content="Same description"></head><body>Content</body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $issues = SeoIssue::where('seo_check_id', $seoCheck->id)
            ->where('type', 'duplicate_meta_description')
            ->get();

        $this->assertCount(2, $issues);
    }

    public function test_detects_missing_alt_text(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'html_content' => '<html><body><img src="image.jpg"><img src="image2.jpg"></body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'missing_alt_text',
            'severity' => SeoIssueSeverity::Notice->value,
        ]);
    }

    public function test_detects_too_few_internal_links(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'internal_link_count' => 1,
            'html_content' => '<html><body><a href="/page2">Link</a></body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'too_few_internal_links',
            'severity' => SeoIssueSeverity::Notice->value,
        ]);
    }

    public function test_detects_too_many_internal_links(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        $manyLinks = array_fill(0, 101, ['url' => 'https://example.com/page', 'text' => 'Link']);
        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'internal_links' => $manyLinks,
            'html_content' => '<html><body>Content with many links</body></html>',
        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'too_many_internal_links',
            'severity' => SeoIssueSeverity::Notice->value,
        ]);
    }

    public function test_detects_mixed_content_on_https_page(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/page1',
            'status_code' => 200,
            'html_content' => '<html><body><img src="http://example.com/image.jpg"></body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'mixed_content',
            'severity' => SeoIssueSeverity::Error->value,
        ]);
    }

    public function test_detects_http_not_redirected_to_https(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'http://example.com/page1',
            'status_code' => 200,
            'html_content' => '<html><body>Content</body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseHas('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'type' => 'http_not_redirected',
            'severity' => SeoIssueSeverity::Error->value,
        ]);
    }

    public function test_skips_detection_for_error_status_codes(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/404-page',
            'status_code' => 404,
            'title' => null,
            'html_content' => '<html><body>Not Found</body></html>',

        ]);

        $this->service->detectIssues($seoCheck);

        $this->assertDatabaseMissing('seo_issues', [
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com/404-page',
            'type' => 'missing_title',
        ]);
    }

    public function test_bulk_inserts_issues_in_batches(): void
    {
        $seoCheck = SeoCheck::factory()->completed()->create();

        for ($i = 0; $i < 150; $i++) {
            SeoCrawlResult::factory()->create([
                'seo_check_id' => $seoCheck->id,
                'url' => "https://example.com/page{$i}",
                'status_code' => 200,
                'title' => null,
                'html_content' => '<html><body>Content</body></html>',

            ]);
        }

        $this->service->detectIssues($seoCheck);

        $issueCount = SeoIssue::where('seo_check_id', $seoCheck->id)->count();
        $this->assertGreaterThan(100, $issueCount);
    }
}
