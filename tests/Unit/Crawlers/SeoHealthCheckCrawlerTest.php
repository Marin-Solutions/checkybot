<?php

use App\Crawlers\SeoHealthCheckCrawler;
use App\Events\CrawlCompleted;
use App\Events\CrawlProgressUpdated;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\Website;
use App\Services\RobotsSitemapService;
use App\Services\SeoIssueDetectionService;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $this->seoCheck = SeoCheck::factory()->create([
        'website_id' => $this->website->id,
        'status' => 'running',
        'started_at' => now(),
    ]);

    $robotsService = Mockery::mock(RobotsSitemapService::class);
    $robotsService->shouldReceive('isUrlAllowed')->andReturn(true);

    $issueDetectionService = Mockery::mock(SeoIssueDetectionService::class);
    $issueDetectionService->shouldReceive('detectIssues')->byDefault();

    $this->app->instance(RobotsSitemapService::class, $robotsService);
    $this->app->instance(SeoIssueDetectionService::class, $issueDetectionService);

    $this->crawler = new SeoHealthCheckCrawler($this->seoCheck);
});

afterEach(function () {
    Mockery::close();
});

test('will crawl updates progress', function () {
    $url = new Uri('https://example.com/page');

    $this->crawler->willCrawl($url, 'Link Text');

    $this->seoCheck->refresh();
    expect($this->seoCheck->total_urls_crawled)->toBe(1);
});

test('will crawl batches seo check progress writes', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();

    for ($i = 1; $i <= 10; $i++) {
        $url = new Uri("https://example.com/page{$i}");
        $this->crawler->willCrawl($url, 'Link');
    }

    $updateQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn ($query) => preg_match('/update\\s+[\"`]?seo_checks[\"`]?/i', $query) === 1);

    expect($updateQueries->count())->toBeLessThanOrEqual(3);
});

test('will crawl broadcasts progress every 5 urls', function () {
    Event::fake();

    for ($i = 1; $i <= 10; $i++) {
        $url = new Uri("https://example.com/page{$i}");
        $this->crawler->willCrawl($url, 'Link');
    }

    Event::assertDispatched(CrawlProgressUpdated::class, 3);
});

test('crawled extracts seo data', function () {
    $url = new Uri('https://example.com/page');
    $html = '<html><head><title>Test Page</title><meta name="description" content="Test description"></head><body><h1>Test H1</h1></body></html>';
    $response = new Response(200, ['Content-Type' => 'text/html'], $html);

    $this->crawler->crawled($url, $response, null, null);
    $this->crawler->finishedCrawling();

    assertDatabaseHas('seo_crawl_results', [
        'seo_check_id' => $this->seoCheck->id,
        'url' => 'https://example.com/page',
        'status_code' => 200,
        'title' => 'Test Page',
        'meta_description' => 'Test description',
        'h1' => 'Test H1',
    ]);
});

test('crawl failed records failed result', function () {
    $url = new Uri('https://example.com/page');
    $request = new Request('GET', $url);
    $exception = new RequestException('Connection timeout', $request);

    $this->crawler->crawlFailed($url, $exception, null, null);
    $this->crawler->finishedCrawling();

    assertDatabaseHas('seo_crawl_results', [
        'seo_check_id' => $this->seoCheck->id,
        'url' => 'https://example.com/page',
        'status_code' => 0,
    ]);
});

test('finished crawling updates seo check status', function () {
    $issueDetectionMock = Mockery::mock(SeoIssueDetectionService::class);
    $issueDetectionMock->shouldReceive('detectIssues')->once();
    $this->app->instance(SeoIssueDetectionService::class, $issueDetectionMock);

    // Recreate crawler to use the new mock
    $crawler = new SeoHealthCheckCrawler($this->seoCheck);
    $crawler->finishedCrawling();

    $this->seoCheck->refresh();
    expect($this->seoCheck->status)->toBe('completed');
    expect($this->seoCheck->finished_at)->not->toBeNull();
});

test('finished crawling bulk inserts crawl results in one query', function () {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = new Response(200, ['Content-Type' => 'text/html'], '<html><body>Content</body></html>');

    $this->crawler->crawled(new Uri('https://example.com/page-1'), $response, null, null);
    $this->crawler->crawled(new Uri('https://example.com/page-2'), $response, null, null);
    $this->crawler->finishedCrawling();

    $insertQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn ($query) => preg_match('/insert\\s+into\\s+[\"`]?seo_crawl_results[\"`]?/i', $query) === 1);

    expect($insertQueries)->toHaveCount(1);
});

test('finished crawling broadcasts completion event', function () {
    Event::fake();

    $issueDetectionMock = Mockery::mock(SeoIssueDetectionService::class);
    $issueDetectionMock->shouldReceive('detectIssues')->once();
    $this->app->instance(SeoIssueDetectionService::class, $issueDetectionMock);

    // Recreate crawler to use the new mock
    $crawler = new SeoHealthCheckCrawler($this->seoCheck);
    $crawler->finishedCrawling();

    Event::assertDispatched(CrawlCompleted::class);
});

test('cancelled check does not write progress', function () {
    Event::fake();

    $this->seoCheck->update([
        'status' => 'cancelled',
        'finished_at' => now(),
        'total_urls_crawled' => 0,
        'total_crawlable_urls' => 10,
    ]);

    $this->crawler->willCrawl(new Uri('https://example.com/page'), 'Link');

    $this->seoCheck->refresh();
    expect($this->seoCheck->status)->toBe('cancelled')
        ->and($this->seoCheck->total_urls_crawled)->toBe(0)
        ->and($this->seoCheck->total_crawlable_urls)->toBe(10);

    Event::assertNotDispatched(CrawlProgressUpdated::class);
});

test('cancelled check does not write crawl results issues or completion', function () {
    Event::fake();

    $issueDetectionMock = Mockery::mock(SeoIssueDetectionService::class);
    $issueDetectionMock->shouldReceive('detectIssues')->never();
    $this->app->instance(SeoIssueDetectionService::class, $issueDetectionMock);

    $crawler = new SeoHealthCheckCrawler($this->seoCheck);
    $response = new Response(200, ['Content-Type' => 'text/html'], '<html><head><title>Cancelled</title></head><body>Content</body></html>');

    $crawler->crawled(new Uri('https://example.com/page'), $response, null, null);

    $this->seoCheck->update([
        'status' => 'cancelled',
        'finished_at' => now(),
    ]);

    $crawler->finishedCrawling();

    $this->seoCheck->refresh();
    expect($this->seoCheck->status)->toBe('cancelled');

    expect(SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->count())->toBe(0);

    Event::assertNotDispatched(CrawlCompleted::class);
});

test('extracts internal links', function () {
    $html = '<html><body><a href="https://example.com/internal">Internal Link</a></body></html>';
    $url = new Uri('https://example.com/page');
    $response = new Response(200, ['Content-Type' => 'text/html'], $html);

    $this->crawler->crawled($url, $response, null, null);
    $this->crawler->finishedCrawling();

    $result = SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->first();
    $internalLinks = $result->internal_links;

    expect($internalLinks)->toBeArray();
    expect($internalLinks)->toHaveCount(1);
    expect($internalLinks[0]['url'])->toBe('https://example.com/internal');
});

test('extracts external links', function () {
    $html = '<html><body><a href="https://external.com/page">External Link</a></body></html>';
    $url = new Uri('https://example.com/page');
    $response = new Response(200, ['Content-Type' => 'text/html'], $html);

    $this->crawler->crawled($url, $response, null, null);
    $this->crawler->finishedCrawling();

    $result = SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->first();
    $externalLinks = $result->external_links;

    expect($externalLinks)->toBeArray();
    expect($externalLinks)->toHaveCount(1);
    expect($externalLinks[0]['url'])->toBe('https://external.com/page');
});

test('extracts canonical url', function () {
    $html = '<html><head><link rel="canonical" href="https://example.com/canonical"></head><body>Content</body></html>';
    $url = new Uri('https://example.com/page');
    $response = new Response(200, ['Content-Type' => 'text/html'], $html);

    $this->crawler->crawled($url, $response, null, null);
    $this->crawler->finishedCrawling();

    assertDatabaseHas('seo_crawl_results', [
        'seo_check_id' => $this->seoCheck->id,
        'canonical_url' => 'https://example.com/canonical',
    ]);
});

test('tracks response time', function () {
    $url = new Uri('https://example.com/page');
    $response = new Response(200, ['Content-Type' => 'text/html'], '<html><body>Content</body></html>');

    $this->crawler->crawled($url, $response, null, null);
    $this->crawler->finishedCrawling();

    $result = SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->first();
    expect($result->response_time_ms)->toBeGreaterThan(0);
});

test('truncates large html content', function () {
    $largeHtml = '<html><body>'.str_repeat('A', 600000).'</body></html>';
    $url = new Uri('https://example.com/page');
    $response = new Response(200, ['Content-Type' => 'text/html'], $largeHtml);

    $this->crawler->crawled($url, $response, null, null);
    $this->crawler->finishedCrawling();

    $result = SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->first();
    expect(strlen($result->html_content))->toBeLessThanOrEqual(500 * 1024);
});

test('respects max urls limit', function () {
    $crawler = new SeoHealthCheckCrawler($this->seoCheck);

    for ($i = 1; $i <= 1100; $i++) {
        $url = new Uri("https://example.com/page{$i}");
        $crawler->willCrawl($url, 'Link');
    }

    $this->seoCheck->refresh();
    expect($this->seoCheck->total_urls_crawled)->toBeLessThanOrEqual(1000);
});

test('records seo data for the last allowed url', function () {
    $crawler = new SeoHealthCheckCrawler($this->seoCheck);

    for ($i = 1; $i <= SeoHealthCheckCrawler::MAX_URLS; $i++) {
        $url = new Uri("https://example.com/page{$i}");
        $crawler->willCrawl($url, 'Link');
    }

    $lastUrl = new Uri('https://example.com/page1000');
    $response = new Response(200, ['Content-Type' => 'text/html'], '<html><title>Last URL</title></html>');

    $crawler->crawled($lastUrl, $response, null, null);
    $crawler->finishedCrawling();

    expect(SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->where('url', (string) $lastUrl)->exists())->toBeTrue();
});

test('populates computed columns on completion', function () {
    $issueDetectionMock = Mockery::mock(SeoIssueDetectionService::class);
    $issueDetectionMock->shouldReceive('detectIssues')->once();
    $this->app->instance(SeoIssueDetectionService::class, $issueDetectionMock);

    // Recreate crawler to use the new mock
    $crawler = new SeoHealthCheckCrawler($this->seoCheck);
    $crawler->finishedCrawling();

    $this->seoCheck->refresh();
    expect($this->seoCheck->computed_health_score)->not->toBeNull();
});

test('counts images on page', function () {
    $html = '<html><body><img src="1.jpg"><img src="2.jpg"><img src="3.jpg"></body></html>';
    $url = new Uri('https://example.com/page');
    $response = new Response(200, ['Content-Type' => 'text/html'], $html);

    $this->crawler->crawled($url, $response, null, null);
    $this->crawler->finishedCrawling();

    $result = SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->first();
    expect($result->image_count)->toBe(3);
});

test('handles empty response body', function () {
    $url = new Uri('https://example.com/empty');
    $response = new Response(200, ['Content-Type' => 'text/html'], '');

    $this->crawler->crawled($url, $response, null, null);
    $this->crawler->finishedCrawling();

    assertDatabaseHas('seo_crawl_results', [
        'seo_check_id' => $this->seoCheck->id,
        'url' => 'https://example.com/empty',
        'page_size_bytes' => 0,
    ]);
});
