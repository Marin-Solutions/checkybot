<?php

namespace Tests\Unit\Models;

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use Tests\TestCase;

class SeoCrawlResultTest extends TestCase
{
    public function test_seo_crawl_result_belongs_to_seo_check(): void
    {
        $check = SeoCheck::factory()->create();
        $result = SeoCrawlResult::factory()->create(['seo_check_id' => $check->id]);

        $this->assertInstanceOf(SeoCheck::class, $result->seoCheck);
        $this->assertEquals($check->id, $result->seoCheck->id);
    }

    public function test_is_success_returns_true_for_2xx_status(): void
    {
        $result = SeoCrawlResult::factory()->create(['status_code' => 200]);

        $this->assertTrue($result->isSuccess());
    }

    public function test_is_success_returns_false_for_non_2xx_status(): void
    {
        $result = SeoCrawlResult::factory()->create(['status_code' => 404]);

        $this->assertFalse($result->isSuccess());
    }

    public function test_is_redirect_returns_true_for_3xx_status(): void
    {
        $result = SeoCrawlResult::factory()->withRedirect()->create();

        $this->assertTrue($result->isRedirect());
    }

    public function test_is_client_error_returns_true_for_4xx_status(): void
    {
        $result = SeoCrawlResult::factory()->create(['status_code' => 404]);

        $this->assertTrue($result->isClientError());
    }

    public function test_is_server_error_returns_true_for_5xx_status(): void
    {
        $result = SeoCrawlResult::factory()->create(['status_code' => 500]);

        $this->assertTrue($result->isServerError());
    }

    public function test_is_error_returns_true_for_any_error_status(): void
    {
        $clientError = SeoCrawlResult::factory()->create(['status_code' => 404]);
        $serverError = SeoCrawlResult::factory()->create(['status_code' => 500]);

        $this->assertTrue($clientError->isError());
        $this->assertTrue($serverError->isError());
    }

    public function test_get_page_size_in_kb_converts_bytes_to_kb(): void
    {
        $result = SeoCrawlResult::factory()->create(['page_size_bytes' => 5120]);

        $this->assertEquals(5.0, $result->getPageSizeInKb());
    }

    public function test_get_html_size_in_kb_converts_bytes_to_kb(): void
    {
        $result = SeoCrawlResult::factory()->create(['html_size_bytes' => 10240]);

        $this->assertEquals(10.0, $result->getHtmlSizeInKb());
    }

    public function test_get_response_time_in_seconds_converts_ms_to_seconds(): void
    {
        $result = SeoCrawlResult::factory()->create(['response_time_ms' => 1500]);

        $this->assertEquals(1.5, $result->getResponseTimeInSeconds());
    }

    public function test_stores_internal_links_as_json(): void
    {
        $links = [
            ['url' => 'https://example.com/page1', 'text' => 'Page 1'],
            ['url' => 'https://example.com/page2', 'text' => 'Page 2'],
        ];

        $result = SeoCrawlResult::factory()->create([
            'internal_links' => json_encode($links),
        ]);

        $this->assertEquals($links, json_decode($result->internal_links, true));
    }

    public function test_stores_external_links_as_json(): void
    {
        $links = [
            ['url' => 'https://external.com', 'text' => 'External Link'],
        ];

        $result = SeoCrawlResult::factory()->create([
            'external_links' => json_encode($links),
        ]);

        $this->assertEquals($links, json_decode($result->external_links, true));
    }

    public function test_tracks_link_counts(): void
    {
        $result = SeoCrawlResult::factory()->create([
            'internal_link_count' => 25,
            'external_link_count' => 5,
        ]);

        $this->assertEquals(25, $result->internal_link_count);
        $this->assertEquals(5, $result->external_link_count);
    }

    public function test_tracks_image_count(): void
    {
        $result = SeoCrawlResult::factory()->create(['image_count' => 12]);

        $this->assertEquals(12, $result->image_count);
    }

    public function test_stores_html_content(): void
    {
        $html = '<html><body><h1>Test Page</h1></body></html>';
        $result = SeoCrawlResult::factory()->create(['html_content' => $html]);

        $this->assertEquals($html, $result->html_content);
    }
}
