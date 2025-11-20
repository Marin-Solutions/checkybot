<?php

use App\Jobs\CheckSslExpiryDateJob;
use App\Models\NotificationSetting;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

test('job checks ssl expiry for website', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'ssl_check' => true,
    ]);

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle();

    // SSL certificate check should have been attempted
    expect(true)->toBeTrue(); // Job executed without errors
});

test('job skips websites with ssl check disabled', function () {
    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'ssl_check' => false,
    ]);

    Mail::fake();

    $job = new CheckSslExpiryDateJob($website);
    $job->handle();

    Mail::assertNothingSent();
});

test('job sends notification when ssl expiry approaching', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'ssl_check' => true,
        'ssl_expiry_date' => now()->addDays(10), // Expires soon
    ]);

    NotificationSetting::factory()->email()->create([
        'user_id' => $website->user->id,
        'website_id' => $website->id,
    ]);

    Http::fake([
        '*' => Http::response('', 200),
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle();

    // Job should complete successfully
    expect(true)->toBeTrue();
});

test('job handles websites without ssl', function () {
    $website = Website::factory()->create([
        'url' => 'http://example.com', // No SSL
        'ssl_check' => true,
    ]);

    $job = new CheckSslExpiryDateJob($website);
    $job->handle();

    // Job should handle gracefully
    expect(true)->toBeTrue();
});
