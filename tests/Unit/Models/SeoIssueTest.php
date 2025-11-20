<?php

use App\Enums\SeoIssueSeverity;
use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;

test('seo issue belongs to seo check', function () {
    $check = SeoCheck::factory()->create();
    $issue = SeoIssue::factory()->create(['seo_check_id' => $check->id]);

    expect($issue->seoCheck)->toBeInstanceOf(SeoCheck::class);
    expect($issue->seoCheck->id)->toBe($check->id);
});

test('seo issue belongs to seo crawl result', function () {
    $crawlResult = SeoCrawlResult::factory()->create();
    $issue = SeoIssue::factory()->create(['seo_crawl_result_id' => $crawlResult->id]);

    expect($issue->seoCrawlResult)->toBeInstanceOf(SeoCrawlResult::class);
    expect($issue->seoCrawlResult->id)->toBe($crawlResult->id);
});

test('seo issue can have error severity', function () {
    $issue = SeoIssue::factory()->error()->create();

    expect($issue->severity)->toBe(SeoIssueSeverity::Error);
});

test('seo issue can have warning severity', function () {
    $issue = SeoIssue::factory()->warning()->create();

    expect($issue->severity)->toBe(SeoIssueSeverity::Warning);
});

test('seo issue can have notice severity', function () {
    $issue = SeoIssue::factory()->notice()->create();

    expect($issue->severity)->toBe(SeoIssueSeverity::Notice);
});

test('seo issue has type field', function () {
    $issue = SeoIssue::factory()->create(['type' => 'missing_title']);

    expect($issue->type)->toBe('missing_title');
});

test('seo issue has url field', function () {
    $url = 'https://example.com/page';
    $issue = SeoIssue::factory()->create(['url' => $url]);

    expect($issue->url)->toBe($url);
});

test('seo issue has title and description', function () {
    $issue = SeoIssue::factory()->create([
        'title' => 'Missing Page Title',
        'description' => 'This page does not have a title tag',
    ]);

    expect($issue->title)->toBe('Missing Page Title');
    expect($issue->description)->toBe('This page does not have a title tag');
});

test('seo issue can store additional data as json', function () {
    $data = [
        'expected' => 'Some value',
        'actual' => 'Different value',
    ];

    $issue = SeoIssue::factory()->create(['data' => json_encode($data)]);

    expect(json_decode($issue->data, true))->toBe($data);
});
