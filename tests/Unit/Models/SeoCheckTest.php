<?php

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use App\Models\Website;

test('seo check belongs to website', function () {
    $website = Website::factory()->create();
    $check = SeoCheck::factory()->create(['website_id' => $website->id]);

    expect($check->website)->toBeInstanceOf(Website::class);
    expect($check->website->id)->toBe($website->id);
});

test('seo check has many crawl results', function () {
    $check = SeoCheck::factory()->create();
    SeoCrawlResult::factory()->count(10)->create(['seo_check_id' => $check->id]);

    expect($check->crawlResults)->toHaveCount(10);
    expect($check->crawlResults->first())->toBeInstanceOf(SeoCrawlResult::class);
});

test('seo check has many seo issues', function () {
    $check = SeoCheck::factory()->create();
    SeoIssue::factory()->count(5)->create(['seo_check_id' => $check->id]);

    expect($check->seoIssues)->toHaveCount(5);
    expect($check->seoIssues->first())->toBeInstanceOf(SeoIssue::class);
});

test('seo check is completed status method', function () {
    $check = SeoCheck::factory()->completed()->create();

    expect($check->isCompleted())->toBeTrue();
    expect($check->isRunning())->toBeFalse();
    expect($check->isFailed())->toBeFalse();
    expect($check->isPending())->toBeFalse();
});

test('seo check is running status method', function () {
    $check = SeoCheck::factory()->running()->create();

    expect($check->isRunning())->toBeTrue();
    expect($check->isCompleted())->toBeFalse();
    expect($check->isFailed())->toBeFalse();
    expect($check->isPending())->toBeFalse();
});

test('seo check is failed status method', function () {
    $check = SeoCheck::factory()->failed()->create();

    expect($check->isFailed())->toBeTrue();
    expect($check->isCompleted())->toBeFalse();
    expect($check->isRunning())->toBeFalse();
    expect($check->isPending())->toBeFalse();
});

test('seo check is pending status method', function () {
    $check = SeoCheck::factory()->create(['status' => 'pending']);

    expect($check->isPending())->toBeTrue();
    expect($check->isCompleted())->toBeFalse();
    expect($check->isRunning())->toBeFalse();
    expect($check->isFailed())->toBeFalse();
});

test('seo check is cancellable when running', function () {
    $check = SeoCheck::factory()->running()->create();

    expect($check->isCancellable())->toBeTrue();
});

test('seo check is not cancellable when completed', function () {
    $check = SeoCheck::factory()->completed()->create();

    expect($check->isCancellable())->toBeFalse();
});

test('seo check progress defaults to zero', function () {
    $check = SeoCheck::factory()->create();

    expect($check->progress)->toBe(0);
});

test('seo check tracks urls crawled', function () {
    $check = SeoCheck::factory()->running()->create([
        'total_urls_crawled' => 50,
        'total_crawlable_urls' => 100,
    ]);

    expect($check->total_urls_crawled)->toBe(50);
    expect($check->total_crawlable_urls)->toBe(100);
});
