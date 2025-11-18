<?php

namespace Tests\Unit\Services;

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Models\Website;
use App\Services\SeoReportGenerationService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeoReportGenerationServiceTest extends TestCase
{
    protected SeoReportGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SeoReportGenerationService;
        Storage::fake('local');
    }

    public function test_generates_html_report(): void
    {
        $website = Website::factory()->create(['name' => 'Test Site']);
        $seoCheck = SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now(),
        ]);

        $filename = $this->service->generateComprehensiveReport($seoCheck, 'html');

        $this->assertStringContainsString('SEO_Report_test-site', $filename);
        $this->assertStringEndsWith('.html', $filename);
        Storage::assertExists("reports/{$filename}");
    }

    public function test_generates_csv_report(): void
    {
        $website = Website::factory()->create(['name' => 'Test Site']);
        $seoCheck = SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now(),
        ]);

        $filename = $this->service->generateComprehensiveReport($seoCheck, 'csv');

        $this->assertStringContainsString('SEO_Report_test-site', $filename);
        $this->assertStringEndsWith('.csv', $filename);
        Storage::assertExists("reports/{$filename}");

        $content = Storage::get("reports/{$filename}");
        $this->assertStringContainsString('URL,"Issue Type",Severity', $content);
    }

    public function test_generates_json_report(): void
    {
        $website = Website::factory()->create(['name' => 'Test Site']);
        $seoCheck = SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now(),
        ]);

        $filename = $this->service->generateComprehensiveReport($seoCheck, 'json');

        $this->assertStringContainsString('SEO_Report_test-site', $filename);
        $this->assertStringEndsWith('.json', $filename);
        Storage::assertExists("reports/{$filename}");

        $content = Storage::get("reports/{$filename}");
        $data = json_decode($content, true);

        $this->assertArrayHasKey('report_metadata', $data);
        $this->assertArrayHasKey('seo_check_summary', $data);
        $this->assertArrayHasKey('issue_summary', $data);
    }

    public function test_json_report_contains_correct_metadata(): void
    {
        $website = Website::factory()->create(['name' => 'Example Site', 'url' => 'https://example.com']);
        $seoCheck = SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now(),
            'total_urls_crawled' => 50,
            'computed_health_score' => 85.5,
        ]);

        $filename = $this->service->generateComprehensiveReport($seoCheck, 'json');
        $content = Storage::get("reports/{$filename}");
        $data = json_decode($content, true);

        $this->assertEquals('Example Site', $data['report_metadata']['website']['name']);
        $this->assertEquals('https://example.com', $data['report_metadata']['website']['url']);
        $this->assertEquals(50, $data['seo_check_summary']['total_urls_crawled']);
        $this->assertEquals(85.5, $data['seo_check_summary']['health_score']);
    }

    public function test_generates_historical_report(): void
    {
        $website = Website::factory()->create(['name' => 'Test Site']);

        SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now()->subDays(5),
            'computed_health_score' => 80,
            'computed_errors_count' => 5,
        ]);

        SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now()->subDays(2),
            'computed_health_score' => 85,
            'computed_errors_count' => 3,
        ]);

        $filename = $this->service->generateHistoricalReport($website, 30);

        $this->assertStringContainsString('SEO_Historical_Report_test-site', $filename);
        Storage::assertExists("reports/{$filename}");

        $content = Storage::get("reports/{$filename}");
        $data = json_decode($content, true);

        $this->assertArrayHasKey('trend_analysis', $data);
        $this->assertArrayHasKey('summary_statistics', $data);
        $this->assertArrayHasKey('check_history', $data);
        $this->assertEquals(2, $data['report_metadata']['checks_analyzed']);
    }

    public function test_historical_report_calculates_trend_correctly(): void
    {
        $website = Website::factory()->create();

        SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now()->subDays(10),
            'computed_health_score' => 70,
            'total_urls_crawled' => 40,
        ]);

        SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now()->subDays(5),
            'computed_health_score' => 80,
            'total_urls_crawled' => 50,
        ]);

        SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now()->subDays(1),
            'computed_health_score' => 90,
            'total_urls_crawled' => 60,
        ]);

        $filename = $this->service->generateHistoricalReport($website, 30);
        $content = Storage::get("reports/{$filename}");
        $data = json_decode($content, true);

        $this->assertEquals(80, $data['summary_statistics']['average_health_score']);
        $this->assertEquals(90, $data['summary_statistics']['best_health_score']);
        $this->assertEquals(70, $data['summary_statistics']['worst_health_score']);
    }

    public function test_cleans_up_old_reports(): void
    {
        Storage::put('reports/old_report_1.json', 'old data');
        Storage::put('reports/old_report_2.json', 'old data');
        Storage::put('reports/recent_report.json', 'recent data');

        // Mock the lastModified to return old dates for first two files
        $oldTimestamp = now()->subDays(40)->timestamp;
        $recentTimestamp = now()->timestamp;

        Storage::shouldReceive('files')
            ->with('reports')
            ->andReturn([
                'reports/old_report_1.json',
                'reports/old_report_2.json',
                'reports/recent_report.json',
            ]);

        Storage::shouldReceive('lastModified')
            ->with('reports/old_report_1.json')
            ->andReturn($oldTimestamp);

        Storage::shouldReceive('lastModified')
            ->with('reports/old_report_2.json')
            ->andReturn($oldTimestamp);

        Storage::shouldReceive('lastModified')
            ->with('reports/recent_report.json')
            ->andReturn($recentTimestamp);

        Storage::shouldReceive('delete')
            ->with('reports/old_report_1.json')
            ->once();

        Storage::shouldReceive('delete')
            ->with('reports/old_report_2.json')
            ->once();

        $deletedCount = $this->service->cleanupOldReports(30);

        $this->assertEquals(2, $deletedCount);
    }

    public function test_filename_generation_uses_safe_names(): void
    {
        $website = Website::factory()->create(['name' => 'Test & Site with Spaces!']);
        $seoCheck = SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now(),
        ]);

        $filename = $this->service->generateComprehensiveReport($seoCheck, 'json');

        $this->assertStringContainsString('test-site-with-spaces', $filename);
        $this->assertStringNotContainsString('&', $filename);
        $this->assertStringNotContainsString('!', $filename);
    }

    public function test_csv_report_includes_all_columns(): void
    {
        $website = Website::factory()->create();
        $seoCheck = SeoCheck::factory()->completed()->create([
            'website_id' => $website->id,
            'finished_at' => now(),
        ]);

        $crawlResult = SeoCrawlResult::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'url' => 'https://example.com',
            'title' => 'Example Page',
            'status_code' => 200,
        ]);

        SeoIssue::factory()->create([
            'seo_check_id' => $seoCheck->id,
            'seo_crawl_result_id' => $crawlResult->id,
            'url' => 'https://example.com',
            'type' => 'missing_h1',
            'severity' => 'warning',
            'title' => 'Missing H1 Tag',
        ]);

        $filename = $this->service->generateComprehensiveReport($seoCheck, 'csv');
        $content = Storage::get("reports/{$filename}");

        $this->assertStringContainsString('URL', $content);
        $this->assertStringContainsString('Issue Type', $content);
        $this->assertStringContainsString('Severity', $content);
        $this->assertStringContainsString('Title', $content);
        $this->assertStringContainsString('Description', $content);
        $this->assertStringContainsString('missing_h1', $content);
    }

    public function test_get_report_download_url_returns_correct_route(): void
    {
        $filename = 'test-report.json';
        $url = $this->service->getReportDownloadUrl($filename);

        $this->assertStringContainsString('test-report.json', $url);
    }
}
