<?php

use App\Models\SeoCheck;
use App\Models\Website;
use App\Services\SeoHealthCheckService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->service = app(SeoHealthCheckService::class);
});

test('start manual check creates seo check record', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $check = $this->service->startManualCheck($website);

    expect($check)->toBeInstanceOf(SeoCheck::class);
    expect($check->website_id)->toBe($website->id);
    expect($check->status)->toBe('pending');
});

test('start manual check dispatches job', function () {
    Queue::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $this->service->startManualCheck($website);

    Queue::assertPushed(\App\Jobs\SeoHealthCheckJob::class);
});

test('cannot start check if already running', function () {
    $website = Website::factory()->create();

    SeoCheck::factory()->running()->create([
        'website_id' => $website->id,
    ]);

    $this->service->startManualCheck($website);
})->throws(\Exception::class);

test('check initializes with zero progress', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
    ]);

    $check = $this->service->startManualCheck($website);

    expect($check->progress)->toEqual(0);
    expect($check->total_urls_crawled)->toEqual(0);
});
