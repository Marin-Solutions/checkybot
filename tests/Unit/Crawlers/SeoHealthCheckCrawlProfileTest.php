<?php

use App\Crawlers\SeoHealthCheckCrawlProfile;
use App\Models\SeoCheck;
use App\Models\Website;
use GuzzleHttp\Psr7\Uri;

test('seo health check crawl profile allows internal urls', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);
    $seoCheck = SeoCheck::factory()->running()->create([
        'website_id' => $website->id,
    ]);

    $profile = new SeoHealthCheckCrawlProfile($seoCheck, 'https://example.com');

    expect($profile->shouldCrawl(new Uri('https://example.com/page')))->toBeTrue();
});

test('seo health check crawl profile rejects external urls', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);
    $seoCheck = SeoCheck::factory()->running()->create([
        'website_id' => $website->id,
    ]);

    $profile = new SeoHealthCheckCrawlProfile($seoCheck, 'https://example.com');

    expect($profile->shouldCrawl(new Uri('https://external.example/page')))->toBeFalse();
});

test('seo health check crawl profile stops scheduling urls after cancellation', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);
    $seoCheck = SeoCheck::factory()->running()->create([
        'website_id' => $website->id,
    ]);

    $profile = new SeoHealthCheckCrawlProfile($seoCheck, 'https://example.com');

    expect($profile->shouldCrawl(new Uri('https://example.com/page')))->toBeTrue();

    $seoCheck->update([
        'status' => SeoCheck::STATUS_CANCELLED,
    ]);

    expect($profile->shouldCrawl(new Uri('https://example.com/next-page')))->toBeFalse();
});
