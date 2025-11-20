<?php

use App\Enums\SeoIssueSeverity;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Services\SeoIssueDetectionService;

beforeEach(function () {
    $this->service = new SeoIssueDetectionService;
});

test('detects missing title issues', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/page1',
        'status_code' => 200,
        'title' => null,
        'html_content' => '<html><body>Content without title</body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'missing_title',
        'severity' => SeoIssueSeverity::Error->value,
    ]);
});

test('detects title too short issues', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/page1',
        'status_code' => 200,
        'title' => 'Short',
        'html_content' => '<html><head><title>Short</title></head><body>Content</body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'title_too_short',
        'severity' => SeoIssueSeverity::Notice->value,
    ]);
});

test('detects title too long issues', function () {
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

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'title_too_long',
        'severity' => SeoIssueSeverity::Notice->value,
    ]);
});

test('detects missing meta description', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/page1',
        'status_code' => 200,
        'meta_description' => null,
        'html_content' => '<html><head><title>Page</title></head><body>Content</body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'missing_meta_description',
        'severity' => SeoIssueSeverity::Warning->value,
    ]);
});

test('detects missing h1 tag', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/page1',
        'status_code' => 200,
        'h1' => null,
        'html_content' => '<html><head><title>Page</title></head><body><p>Content without H1</p></body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'missing_h1',
        'severity' => SeoIssueSeverity::Warning->value,
    ]);
});

test('detects multiple h1 tags', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/page1',
        'status_code' => 200,
        'html_content' => '<html><head><title>Page</title></head><body><h1>First</h1><h1>Second</h1></body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'duplicate_h1',
        'severity' => SeoIssueSeverity::Warning->value,
    ]);
});

test('detects slow response time', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/page1',
        'status_code' => 200,
        'response_time_ms' => 1500,
        'html_content' => '<html><head><title>Page</title></head><body>Content</body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'slow_response',
        'severity' => SeoIssueSeverity::Warning->value,
    ]);
});

test('detects duplicate titles', function () {
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

    expect($issues)->toHaveCount(2);
});

test('detects duplicate meta descriptions', function () {
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

    expect($issues)->toHaveCount(2);
});

test('detects missing alt text', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/page1',
        'status_code' => 200,
        'html_content' => '<html><body><img src="image.jpg"><img src="image2.jpg"></body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'missing_alt_text',
        'severity' => SeoIssueSeverity::Notice->value,
    ]);
});

test('detects too few internal links', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/page1',
        'status_code' => 200,
        'internal_link_count' => 1,
        'html_content' => '<html><body><a href="/page2">Link</a></body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'too_few_internal_links',
        'severity' => SeoIssueSeverity::Notice->value,
    ]);
});

test('detects too many internal links', function () {
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

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'too_many_internal_links',
        'severity' => SeoIssueSeverity::Notice->value,
    ]);
});

test('detects mixed content on https page', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/page1',
        'status_code' => 200,
        'html_content' => '<html><body><img src="http://example.com/image.jpg"></body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'mixed_content',
        'severity' => SeoIssueSeverity::Error->value,
    ]);
});

test('detects http not redirected to https', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'http://example.com/page1',
        'status_code' => 200,
        'html_content' => '<html><body>Content</body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseHas('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'type' => 'http_not_redirected',
        'severity' => SeoIssueSeverity::Error->value,
    ]);
});

test('skips detection for error status codes', function () {
    $seoCheck = SeoCheck::factory()->completed()->create();

    SeoCrawlResult::factory()->create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/404-page',
        'status_code' => 404,
        'title' => null,
        'html_content' => '<html><body>Not Found</body></html>',

    ]);

    $this->service->detectIssues($seoCheck);

    assertDatabaseMissing('seo_issues', [
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/404-page',
        'type' => 'missing_title',
    ]);
});

test('bulk inserts issues in batches', function () {
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
    expect($issueCount)->toBeGreaterThan(100);
});
