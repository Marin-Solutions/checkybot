<?php

namespace Tests\Unit\Crawlers;

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
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class SeoHealthCheckCrawlerTest extends TestCase
{
    protected SeoCheck $seoCheck;

    protected Website $website;

    protected SeoHealthCheckCrawler $crawler;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->app->instance(RobotsSitemapService::class, $robotsService);
        $this->app->instance(SeoIssueDetectionService::class, Mockery::mock(SeoIssueDetectionService::class));

        $this->crawler = new SeoHealthCheckCrawler($this->seoCheck);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_will_crawl_updates_progress(): void
    {
        $url = new Uri('https://example.com/page');

        $this->crawler->willCrawl($url, 'Link Text');

        $this->seoCheck->refresh();
        $this->assertEquals(1, $this->seoCheck->total_urls_crawled);
    }

    public function test_will_crawl_broadcasts_progress_every_5_urls(): void
    {
        Event::fake();

        for ($i = 1; $i <= 10; $i++) {
            $url = new Uri("https://example.com/page{$i}");
            $this->crawler->willCrawl($url, 'Link');
        }

        Event::assertDispatched(CrawlProgressUpdated::class, 3);
    }

    public function test_crawled_extracts_seo_data(): void
    {
        $url = new Uri('https://example.com/page');
        $html = '<html><head><title>Test Page</title><meta name="description" content="Test description"></head><body><h1>Test H1</h1></body></html>';
        $response = new Response(200, ['Content-Type' => 'text/html'], $html);

        $this->crawler->crawled($url, $response, null, null);
        $this->crawler->finishedCrawling();

        $this->assertDatabaseHas('seo_crawl_results', [
            'seo_check_id' => $this->seoCheck->id,
            'url' => 'https://example.com/page',
            'status_code' => 200,
            'title' => 'Test Page',
            'meta_description' => 'Test description',
            'h1' => 'Test H1',
        ]);
    }

    public function test_crawl_failed_records_failed_result(): void
    {
        $url = new Uri('https://example.com/page');
        $request = new Request('GET', $url);
        $exception = new RequestException('Connection timeout', $request);

        $this->crawler->crawlFailed($url, $exception, null, null);
        $this->crawler->finishedCrawling();

        $this->assertDatabaseHas('seo_crawl_results', [
            'seo_check_id' => $this->seoCheck->id,
            'url' => 'https://example.com/page',
            'status_code' => 0,
        ]);
    }

    public function test_finished_crawling_updates_seo_check_status(): void
    {
        $this->app->instance(SeoIssueDetectionService::class, Mockery::mock(SeoIssueDetectionService::class, function ($mock) {
            $mock->shouldReceive('detectIssues')->once();
        }));

        $this->crawler->finishedCrawling();

        $this->seoCheck->refresh();
        $this->assertEquals('completed', $this->seoCheck->status);
        $this->assertNotNull($this->seoCheck->finished_at);
    }

    public function test_finished_crawling_broadcasts_completion_event(): void
    {
        Event::fake();

        $this->app->instance(SeoIssueDetectionService::class, Mockery::mock(SeoIssueDetectionService::class, function ($mock) {
            $mock->shouldReceive('detectIssues')->once();
        }));

        $this->crawler->finishedCrawling();

        Event::assertDispatched(CrawlCompleted::class);
    }

    public function test_extracts_internal_links(): void
    {
        $html = '<html><body><a href="https://example.com/internal">Internal Link</a></body></html>';
        $url = new Uri('https://example.com/page');
        $response = new Response(200, ['Content-Type' => 'text/html'], $html);

        $this->crawler->crawled($url, $response, null, null);
        $this->crawler->finishedCrawling();

        $result = SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->first();
        $internalLinks = $result->internal_links;

        $this->assertIsArray($internalLinks);
        $this->assertCount(1, $internalLinks);
        $this->assertEquals('https://example.com/internal', $internalLinks[0]['url']);
    }

    public function test_extracts_external_links(): void
    {
        $html = '<html><body><a href="https://external.com/page">External Link</a></body></html>';
        $url = new Uri('https://example.com/page');
        $response = new Response(200, ['Content-Type' => 'text/html'], $html);

        $this->crawler->crawled($url, $response, null, null);
        $this->crawler->finishedCrawling();

        $result = SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->first();
        $externalLinks = $result->external_links;

        $this->assertIsArray($externalLinks);
        $this->assertCount(1, $externalLinks);
        $this->assertEquals('https://external.com/page', $externalLinks[0]['url']);
    }

    public function test_extracts_canonical_url(): void
    {
        $html = '<html><head><link rel="canonical" href="https://example.com/canonical"></head><body>Content</body></html>';
        $url = new Uri('https://example.com/page');
        $response = new Response(200, ['Content-Type' => 'text/html'], $html);

        $this->crawler->crawled($url, $response, null, null);
        $this->crawler->finishedCrawling();

        $this->assertDatabaseHas('seo_crawl_results', [
            'seo_check_id' => $this->seoCheck->id,
            'canonical_url' => 'https://example.com/canonical',
        ]);
    }

    public function test_tracks_response_time(): void
    {
        $url = new Uri('https://example.com/page');
        $response = new Response(200, ['Content-Type' => 'text/html'], '<html><body>Content</body></html>');

        $this->crawler->crawled($url, $response, null, null);
        $this->crawler->finishedCrawling();

        $result = SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->first();
        $this->assertGreaterThan(0, $result->response_time_ms);
    }

    public function test_truncates_large_html_content(): void
    {
        $largeHtml = '<html><body>'.str_repeat('A', 600000).'</body></html>';
        $url = new Uri('https://example.com/page');
        $response = new Response(200, ['Content-Type' => 'text/html'], $largeHtml);

        $this->crawler->crawled($url, $response, null, null);
        $this->crawler->finishedCrawling();

        $result = SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->first();
        $this->assertLessThanOrEqual(500 * 1024, strlen($result->html_content));
    }

    public function test_respects_max_urls_limit(): void
    {
        $crawler = new SeoHealthCheckCrawler($this->seoCheck);

        for ($i = 1; $i <= 1100; $i++) {
            $url = new Uri("https://example.com/page{$i}");
            $crawler->willCrawl($url, 'Link');
        }

        $this->seoCheck->refresh();
        $this->assertLessThanOrEqual(1000, $this->seoCheck->total_urls_crawled);
    }

    public function test_populates_computed_columns_on_completion(): void
    {
        $this->app->instance(SeoIssueDetectionService::class, Mockery::mock(SeoIssueDetectionService::class, function ($mock) {
            $mock->shouldReceive('detectIssues')->once();
        }));

        $this->crawler->finishedCrawling();

        $this->seoCheck->refresh();
        $this->assertNotNull($this->seoCheck->computed_health_score);
    }

    public function test_counts_images_on_page(): void
    {
        $html = '<html><body><img src="1.jpg"><img src="2.jpg"><img src="3.jpg"></body></html>';
        $url = new Uri('https://example.com/page');
        $response = new Response(200, ['Content-Type' => 'text/html'], $html);

        $this->crawler->crawled($url, $response, null, null);
        $this->crawler->finishedCrawling();

        $result = SeoCrawlResult::where('seo_check_id', $this->seoCheck->id)->first();
        $this->assertEquals(3, $result->image_count);
    }

    public function test_handles_empty_response_body(): void
    {
        $url = new Uri('https://example.com/empty');
        $response = new Response(200, ['Content-Type' => 'text/html'], '');

        $this->crawler->crawled($url, $response, null, null);
        $this->crawler->finishedCrawling();

        $this->assertDatabaseHas('seo_crawl_results', [
            'seo_check_id' => $this->seoCheck->id,
            'url' => 'https://example.com/empty',
            'page_size_bytes' => 0,
        ]);
    }
}
