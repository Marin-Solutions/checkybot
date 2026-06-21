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
    expect($check->isCancelled())->toBeFalse();
});

test('seo check is cancelled status method', function () {
    $check = SeoCheck::factory()->cancelled()->create();

    expect($check->isCancelled())->toBeTrue();
    expect($check->isFailed())->toBeFalse();
    expect($check->isCompleted())->toBeFalse();
    expect($check->isRunning())->toBeFalse();
    expect($check->isPending())->toBeFalse();
});

test('failed seo check factory includes failure details', function () {
    $website = Website::factory()->create([
        'url' => 'https://factory.example.com',
    ]);
    $check = SeoCheck::factory()->failed()->create([
        'website_id' => $website->id,
    ]);

    expect($check->failure_summary)->toBe('SEO crawler failed before the crawl could complete.');
    expect($check->failure_context)->toMatchArray([
        'exception' => 'Exception',
        'website_url' => 'https://factory.example.com',
        'total_urls_crawled' => 0,
    ]);
});

test('failure reason filters only include matching failed seo checks', function () {
    $contextTimeout = SeoCheck::factory()->failed()->create([
        'failure_summary' => 'SEO crawler failed before the crawl could complete.',
        'failure_context' => [
            'exception_class' => 'RuntimeException',
            'exception_message' => 'Operation Timeout while crawling.',
        ],
    ]);
    $summaryTimeout = SeoCheck::factory()->failed()->create([
        'failure_summary' => 'SEO crawler timed out before the crawl could complete.',
        'failure_context' => [
            'exception_class' => 'RuntimeException',
        ],
    ]);
    $otherFailure = SeoCheck::factory()->failed()->create([
        'failure_summary' => 'SEO crawler failed before the crawl could complete.',
        'failure_context' => [
            'exception_class' => 'UnexpectedValueException',
            'exception_message' => 'Crawler returned invalid metadata.',
        ],
    ]);
    $completedWithOldFailureText = SeoCheck::factory()->completed()->create([
        'failure_summary' => 'SEO crawler timed out before a previous retry recovered.',
        'failure_context' => [
            'exception_message' => 'Previous timeout recovered.',
        ],
    ]);

    expect(SeoCheck::applyFailureReasonFilter(SeoCheck::query(), SeoCheck::FAILURE_REASON_TIMEOUT)->pluck('id')->all())
        ->toContain($contextTimeout->id, $summaryTimeout->id)
        ->not->toContain($otherFailure->id, $completedWithOldFailureText->id);

    expect(SeoCheck::applyFailureReasonFilter(SeoCheck::query(), SeoCheck::FAILURE_REASON_OTHER)->pluck('id')->all())
        ->toContain($otherFailure->id)
        ->not->toContain($contextTimeout->id, $summaryTimeout->id, $completedWithOldFailureText->id);
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

test('seo check is not cancellable when cancelled', function () {
    $check = SeoCheck::factory()->cancelled()->create();

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
