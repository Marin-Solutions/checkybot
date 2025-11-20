<?php

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Models\Website;
use App\Services\SeoReportGenerationService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->service = new SeoReportGenerationService;
    Storage::fake('local');
});

test('generates html report', function () {
    $website = Website::factory()->create(['name' => 'Test Site']);
    $seoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $website->id,
        'finished_at' => now(),
    ]);

    $filename = $this->service->generateComprehensiveReport($seoCheck, 'html');

    expect($filename)->toContain('SEO_Report_test-site');
    expect($filename)->toEndWith('.html');
    Storage::assertExists("reports/{$filename}");
});

test('generates csv report', function () {
    $website = Website::factory()->create(['name' => 'Test Site']);
    $seoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $website->id,
        'finished_at' => now(),
    ]);

    $filename = $this->service->generateComprehensiveReport($seoCheck, 'csv');

    expect($filename)->toContain('SEO_Report_test-site');
    expect($filename)->toEndWith('.csv');
    Storage::assertExists("reports/{$filename}");

    $content = Storage::get("reports/{$filename}");
    expect($content)->toContain('URL,"Issue Type",Severity');
});

test('generates json report', function () {
    $website = Website::factory()->create(['name' => 'Test Site']);
    $seoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $website->id,
        'finished_at' => now(),
    ]);

    $filename = $this->service->generateComprehensiveReport($seoCheck, 'json');

    expect($filename)->toContain('SEO_Report_test-site');
    expect($filename)->toEndWith('.json');
    Storage::assertExists("reports/{$filename}");

    $content = Storage::get("reports/{$filename}");
    $data = json_decode($content, true);

    expect($data)->toHaveKeys(['report_metadata', 'seo_check_summary', 'issue_summary']);
});

test('json report contains correct metadata', function () {
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

    expect($data['report_metadata']['website']['name'])->toBe('Example Site');
    expect($data['report_metadata']['website']['url'])->toBe('https://example.com');
    expect($data['seo_check_summary']['total_urls_crawled'])->toBe(50);
    expect($data['seo_check_summary']['health_score'])->toBe(85.5);
});

test('generates historical report', function () {
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

    expect($filename)->toContain('SEO_Historical_Report_test-site');
    Storage::assertExists("reports/{$filename}");

    $content = Storage::get("reports/{$filename}");
    $data = json_decode($content, true);

    expect($data)->toHaveKeys(['trend_analysis', 'summary_statistics', 'check_history']);
    expect($data['report_metadata']['checks_analyzed'])->toBe(2);
});

test('historical report calculates trend correctly', function () {
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

    expect($data['summary_statistics']['average_health_score'])->toBe(80);
    expect($data['summary_statistics']['best_health_score'])->toBe(90);
    expect($data['summary_statistics']['worst_health_score'])->toBe(70);
});

test('cleans up old reports', function () {
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

    expect($deletedCount)->toBe(2);
});

test('filename generation uses safe names', function () {
    $website = Website::factory()->create(['name' => 'Test & Site with Spaces!']);
    $seoCheck = SeoCheck::factory()->completed()->create([
        'website_id' => $website->id,
        'finished_at' => now(),
    ]);

    $filename = $this->service->generateComprehensiveReport($seoCheck, 'json');

    expect($filename)->toContain('test-site-with-spaces');
    expect($filename)->not->toContain('&');
    expect($filename)->not->toContain('!');
});

test('csv report includes all columns', function () {
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

    expect($content)->toContain('URL');
    expect($content)->toContain('Issue Type');
    expect($content)->toContain('Severity');
    expect($content)->toContain('Title');
    expect($content)->toContain('Description');
    expect($content)->toContain('missing_h1');
});

test('get report download url returns correct route', function () {
    $filename = 'test-report.json';
    $url = $this->service->getReportDownloadUrl($filename);

    expect($url)->toContain('test-report.json');
});
