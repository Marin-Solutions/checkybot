<?php

use App\Jobs\LogUptimeSslJob;
use App\Models\NotificationSetting;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

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

test('job records danger status history and sends notifications for failed package-managed heartbeats', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle();

    $website->refresh();
    $history = WebsiteLogHistory::where('website_id', $website->id)->latest()->first();

    expect($website->current_status)->toBe('danger');
    expect($website->last_heartbeat_at)->not->toBeNull();
    expect($history?->status)->toBe('danger');

    Mail::assertSent(\App\Mail\HealthStatusAlert::class);
});

test('job sends recovery notifications when a package-managed website returns to healthy', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
        'current_status' => 'danger',
        'stale_at' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle();

    $website->refresh();

    expect($website->current_status)->toBe('healthy')
        ->and($website->stale_at)->toBeNull();

    Mail::assertSent(\App\Mail\HealthStatusAlert::class, function (\App\Mail\HealthStatusAlert $mail): bool {
        return $mail->event === 'recovered'
            && $mail->eventLabel === 'recovered'
            && $mail->status === 'healthy'
            && $mail->summary === 'Website heartbeat succeeded with HTTP status 200.';
    });
});

test('job sends recovery notifications when a warning website returns to healthy', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
        'current_status' => 'warning',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle();

    Mail::assertSent(\App\Mail\HealthStatusAlert::class, function (\App\Mail\HealthStatusAlert $mail): bool {
        return $mail->event === 'recovered'
            && $mail->eventLabel === 'recovered'
            && $mail->status === 'healthy';
    });
});

test('job sends notifications for failed manual website heartbeats', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'manual',
        'current_status' => 'healthy',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle();

    $website->refresh();

    expect($website->current_status)->toBe('danger');

    Mail::assertSent(\App\Mail\HealthStatusAlert::class, 1);
});

test('job sends recovery notifications when a manual website returns to healthy', function () {
    Http::fake([
        '*' => Http::response('', 200),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'manual',
        'current_status' => 'danger',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle();

    $website->refresh();

    expect($website->current_status)->toBe('healthy');

    Mail::assertSent(\App\Mail\HealthStatusAlert::class, function (\App\Mail\HealthStatusAlert $mail): bool {
        return $mail->event === 'recovered'
            && $mail->eventLabel === 'recovered'
            && $mail->status === 'healthy';
    });
});

test('job does not notify when a manual website remains in the same failing status', function () {
    Http::fake([
        '*' => Http::response('', 500),
    ]);

    Mail::fake();

    $website = Website::factory()->create([
        'url' => 'https://example.com',
        'uptime_check' => true,
        'source' => 'manual',
        'current_status' => 'danger',
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $job = new LogUptimeSslJob($website);
    $job->handle();

    $website->refresh();

    expect($website->current_status)->toBe('danger');

    Mail::assertNothingSent();
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
