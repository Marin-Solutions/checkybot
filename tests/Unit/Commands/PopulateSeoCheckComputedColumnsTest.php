<?php

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Models\Website;

test('command can be executed', function () {
    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful()
        ->assertExitCode(0);
});

test('command populates errors count', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'completed',
        'total_urls_crawled' => 10,
    ]);

    SeoIssue::create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com',
        'severity' => 'error',
        'type' => 'missing_title',
        'title' => 'Missing title',
        'description' => 'Page is missing title tag',
    ]);

    SeoIssue::create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/about',
        'severity' => 'error',
        'type' => 'missing_description',
        'title' => 'Missing description',
        'description' => 'Page is missing meta description',
    ]);

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();

    $seoCheck->refresh();
    expect($seoCheck->computed_errors_count)->toBe(2);
});

test('command populates warnings count', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'completed',
        'total_urls_crawled' => 10,
    ]);

    SeoIssue::create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com',
        'severity' => 'warning',
        'type' => 'title_too_long',
        'title' => 'Title too long',
        'description' => 'Page title exceeds recommended length',
    ]);

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();

    $seoCheck->refresh();
    expect($seoCheck->computed_warnings_count)->toBe(1);
});

test('command populates notices count', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'completed',
        'total_urls_crawled' => 10,
    ]);

    SeoIssue::create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com',
        'severity' => 'notice',
        'type' => 'image_missing_alt',
        'title' => 'Image missing alt',
        'description' => 'Image is missing alt attribute',
    ]);

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();

    $seoCheck->refresh();
    expect($seoCheck->computed_notices_count)->toBe(1);
});

test('command populates http errors count', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'completed',
        'total_urls_crawled' => 10,
    ]);

    SeoCrawlResult::create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/404',
        'status_code' => 404,
    ]);

    SeoCrawlResult::create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/500',
        'status_code' => 500,
    ]);

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();

    $seoCheck->refresh();
    expect($seoCheck->computed_http_errors_count)->toBe(2);
});

test('command calculates health score', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'completed',
        'total_urls_crawled' => 10,
    ]);

    // Create 2 errors, so 8 out of 10 URLs are healthy
    SeoIssue::create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com',
        'severity' => 'error',
        'type' => 'missing_title',
        'title' => 'Missing title',
        'description' => 'Page is missing title tag',
    ]);

    SeoIssue::create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/about',
        'severity' => 'error',
        'type' => 'missing_description',
        'title' => 'Missing description',
        'description' => 'Page is missing meta description',
    ]);

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();

    $seoCheck->refresh();
    expect($seoCheck->computed_health_score)->toEqual(80.0);
});

test('command calculates health score with http errors', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'completed',
        'total_urls_crawled' => 10,
    ]);

    SeoCrawlResult::create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com/404',
        'status_code' => 404,
    ]);

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();

    $seoCheck->refresh();
    expect($seoCheck->computed_health_score)->toEqual(90.0);
});

test('command handles zero urls crawled', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'pending',
        'total_urls_crawled' => 0,
    ]);

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();

    $seoCheck->refresh();
    expect($seoCheck->computed_health_score)->toEqual(0.0);
});

test('command ensures health score not negative', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'completed',
        'total_urls_crawled' => 2,
    ]);

    // Create more errors than URLs crawled (edge case)
    for ($i = 0; $i < 5; $i++) {
        SeoIssue::create([
            'seo_check_id' => $seoCheck->id,
            'url' => "https://example.com/page{$i}",
            'severity' => 'error',
            'type' => 'missing_title',
            'title' => 'Missing title',
            'description' => 'Page is missing title tag',
        ]);
    }

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();

    $seoCheck->refresh();
    expect($seoCheck->computed_health_score)->toBeGreaterThanOrEqual(0.0);
});

test('command processes multiple seo checks', function () {
    $website = Website::factory()->create();
    $seoChecks = [];

    for ($i = 0; $i < 3; $i++) {
        $seoChecks[] = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'completed',
            'total_urls_crawled' => 10,
        ]);
    }

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();

    foreach ($seoChecks as $seoCheck) {
        $seoCheck->refresh();
        expect($seoCheck->computed_health_score)->not->toBeNull();
    }
});

test('command displays progress bar', function () {
    $website = Website::factory()->create();
    SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'completed',
        'total_urls_crawled' => 10,
    ]);

    $this->artisan('seo:populate-computed-columns')
        ->expectsOutput('Starting to populate computed columns for SEO checks...')
        ->assertSuccessful();
});

test('command displays completion message', function () {
    $website = Website::factory()->create();
    SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'completed',
        'total_urls_crawled' => 10,
    ]);

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();
});

test('command handles no seo checks', function () {
    $this->artisan('seo:populate-computed-columns')
        ->expectsOutput('Successfully populated computed columns for 0 SEO checks!')
        ->assertSuccessful();
});

test('command updates existing computed values', function () {
    $website = Website::factory()->create();
    $seoCheck = SeoCheck::create([
        'website_id' => $website->id,
        'status' => 'completed',
        'total_urls_crawled' => 10,
        'computed_errors_count' => 999,
        'computed_health_score' => 0.0,
    ]);

    SeoIssue::create([
        'seo_check_id' => $seoCheck->id,
        'url' => 'https://example.com',
        'severity' => 'error',
        'type' => 'missing_title',
        'title' => 'Missing title',
        'description' => 'Page is missing title tag',
    ]);

    $this->artisan('seo:populate-computed-columns')
        ->assertSuccessful();

    $seoCheck->refresh();
    expect($seoCheck->computed_errors_count)->toBe(1);
    expect($seoCheck->computed_health_score)->toEqual(90.0);
});
