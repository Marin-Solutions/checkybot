<?php

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;

test('seo crawl result belongs to seo check', function () {
    $check = SeoCheck::factory()->create();
    $result = SeoCrawlResult::factory()->create(['seo_check_id' => $check->id]);

    expect($result->seoCheck)->toBeInstanceOf(SeoCheck::class);
    expect($result->seoCheck->id)->toBe($check->id);
});

test('is success returns true for 2xx status', function () {
    $result = SeoCrawlResult::factory()->create(['status_code' => 200]);

    expect($result->isSuccess())->toBeTrue();
});

test('is success returns false for non 2xx status', function () {
    $result = SeoCrawlResult::factory()->create(['status_code' => 404]);

    expect($result->isSuccess())->toBeFalse();
});

test('is redirect returns true for 3xx status', function () {
    $result = SeoCrawlResult::factory()->withRedirect()->create();

    expect($result->isRedirect())->toBeTrue();
});

test('is client error returns true for 4xx status', function () {
    $result = SeoCrawlResult::factory()->create(['status_code' => 404]);

    expect($result->isClientError())->toBeTrue();
});

test('is server error returns true for 5xx status', function () {
    $result = SeoCrawlResult::factory()->create(['status_code' => 500]);

    expect($result->isServerError())->toBeTrue();
});

test('is error returns true for any error status', function () {
    $clientError = SeoCrawlResult::factory()->create(['status_code' => 404]);
    $serverError = SeoCrawlResult::factory()->create(['status_code' => 500]);

    expect($clientError->isError())->toBeTrue();
    expect($serverError->isError())->toBeTrue();
});

test('get page size in kb converts bytes to kb', function () {
    $result = SeoCrawlResult::factory()->create(['page_size_bytes' => 5120]);

    expect($result->getPageSizeInKb())->toBe(5.0);
});

test('get html size in kb converts bytes to kb', function () {
    $result = SeoCrawlResult::factory()->create(['html_size_bytes' => 10240]);

    expect($result->getHtmlSizeInKb())->toBe(10.0);
});

test('get response time in seconds converts ms to seconds', function () {
    $result = SeoCrawlResult::factory()->create(['response_time_ms' => 1500]);

    expect($result->getResponseTimeInSeconds())->toBe(1.5);
});

test('stores internal links as json', function () {
    $links = [
        ['url' => 'https://example.com/page1', 'text' => 'Page 1'],
        ['url' => 'https://example.com/page2', 'text' => 'Page 2'],
    ];

    $result = SeoCrawlResult::factory()->create([
        'internal_links' => json_encode($links),
    ]);

    expect(json_decode($result->internal_links, true))->toBe($links);
});

test('stores external links as json', function () {
    $links = [
        ['url' => 'https://external.com', 'text' => 'External Link'],
    ];

    $result = SeoCrawlResult::factory()->create([
        'external_links' => json_encode($links),
    ]);

    expect(json_decode($result->external_links, true))->toBe($links);
});

test('tracks link counts', function () {
    $result = SeoCrawlResult::factory()->create([
        'internal_link_count' => 25,
        'external_link_count' => 5,
    ]);

    expect($result->internal_link_count)->toBe(25);
    expect($result->external_link_count)->toBe(5);
});

test('tracks image count', function () {
    $result = SeoCrawlResult::factory()->create(['image_count' => 12]);

    expect($result->image_count)->toBe(12);
});

test('stores html content', function () {
    $html = '<html><body><h1>Test Page</h1></body></html>';
    $result = SeoCrawlResult::factory()->create(['html_content' => $html]);

    expect($result->html_content)->toBe($html);
});
