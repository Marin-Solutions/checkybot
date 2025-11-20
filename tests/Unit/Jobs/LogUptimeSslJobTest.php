<?php

use App\Jobs\LogUptimeSslJob;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Support\Facades\Http;

test('job creates log history for successful check', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle();

    assertDatabaseHas('website_log_history', [
        'website_id' => $website->id,
        'http_status_code' => 200,
    ]);
});

test('job records response time', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle();

    $log = WebsiteLogHistory::where('website_id', $website->id)->first();

    expect($log)->not->toBeNull();
    expect($log->speed)->toBeInt();
    expect($log->speed)->toBeGreaterThanOrEqual(0);
});

test('job handles failed requests', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle();

    assertDatabaseHas('website_log_history', [
        'website_id' => $website->id,
        'http_status_code' => 500,
    ]);
});

test('job skips websites with uptime check disabled', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => false,
    ]);

    $job = new LogUptimeSslJob($website);
    $job->handle();

    assertDatabaseMissing('website_log_history', [
        'website_id' => $website->id,
    ]);
});
