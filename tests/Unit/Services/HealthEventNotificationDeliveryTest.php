<?php

use App\Enums\WebsiteServicesEnum;
use App\Mail\HealthStatusAlert;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use App\Models\Website;
use App\Services\HealthEventNotificationService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

test('notifyWebsite returns true when no notification settings are configured', function () {
    $website = Website::factory()->create([
        'silenced_until' => null,
    ]);

    $result = app(HealthEventNotificationService::class)
        ->notifyWebsite($website, 'heartbeat', 'danger', 'Returned HTTP 500.');

    expect($result)->toBeTrue();
});

test('notifyWebsite returns true and dispatches mail on a healthy delivery path', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'silenced_until' => null,
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $result = app(HealthEventNotificationService::class)
        ->notifyWebsite($website, 'heartbeat', 'danger', 'Returned HTTP 500.');

    expect($result)->toBeTrue();
    Mail::assertSent(HealthStatusAlert::class, 1);
});

test('notifyWebsite isolates a single channel failure, logs it, and returns false when every attempt failed', function () {
    Log::spy();

    $website = Website::factory()->create([
        'silenced_until' => null,
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    // Mock the chain Mail::to($address)->send($mailable) so the underlying
    // transport throws — the service must catch, log, and return false.
    $pendingMail = Mockery::mock(PendingMail::class);
    $pendingMail->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));
    Mail::shouldReceive('to')->once()->andReturn($pendingMail);

    $result = app(HealthEventNotificationService::class)
        ->notifyWebsite($website, 'heartbeat', 'danger', 'Returned HTTP 500.');

    expect($result)->toBeFalse();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'Failed to deliver health notification mail'));
});

test('notifyWebsite re-reads silenced_until and skips delivery when the monitor was snoozed concurrently', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'silenced_until' => null,
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    // Simulate the operator snoozing the monitor AFTER the in-memory model
    // was loaded by the caller — the local instance is still stale (null),
    // but the persisted state now carries an active future snooze.
    Website::query()
        ->whereKey($website->id)
        ->update(['silenced_until' => now()->addHour()]);

    expect($website->silenced_until)->toBeNull();

    $result = app(HealthEventNotificationService::class)
        ->notifyWebsite($website, 'heartbeat', 'danger', 'Returned HTTP 500.');

    expect($result)->toBeTrue();
    Mail::assertNothingSent();
});

test('notifyWebsite honours a manual unsnooze even when the in-memory model still carries a future silenced_until', function () {
    Mail::fake();

    // Caller loaded the model at the start of a check cycle while it was
    // snoozed. Operator unsnoozes via the UI mid-cycle (DB row → null),
    // but the caller still holds the stale future timestamp in memory.
    // The service must consult the persisted state and deliver the alert.
    $website = Website::factory()->create([
        'silenced_until' => now()->addHour(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    Website::query()
        ->whereKey($website->id)
        ->update(['silenced_until' => null]);

    expect($website->silenced_until)->not->toBeNull()
        ->and($website->silenced_until->isFuture())->toBeTrue();

    $result = app(HealthEventNotificationService::class)
        ->notifyWebsite($website, 'heartbeat', 'danger', 'Returned HTTP 500.');

    expect($result)->toBeTrue();
    Mail::assertSent(HealthStatusAlert::class, 1);
});

test('notifyApi re-reads silenced_until and skips delivery when the monitor was snoozed concurrently', function () {
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'silenced_until' => null,
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => WebsiteServicesEnum::API_MONITOR,
        ]);

    MonitorApis::query()
        ->whereKey($monitor->id)
        ->update(['silenced_until' => now()->addHour()]);

    expect($monitor->silenced_until)->toBeNull();

    $result = app(HealthEventNotificationService::class)
        ->notifyApi($monitor, 'heartbeat', 'danger', 'Latency exceeded threshold.');

    expect($result)->toBeTrue();
    Mail::assertNothingSent();
});

test('notifyWebsite returns true when at least one channel delivers despite another failing', function () {
    Log::spy();

    $website = Website::factory()->create([
        'silenced_until' => null,
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->count(2)
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    // First mail send throws, second succeeds. Service must isolate the
    // failure and treat the partial success as a non-retry condition.
    $pendingMailFailing = Mockery::mock(PendingMail::class);
    $pendingMailFailing->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP transient'));

    $pendingMailSucceeding = Mockery::mock(PendingMail::class);
    $pendingMailSucceeding->shouldReceive('send')->once();

    Mail::shouldReceive('to')
        ->twice()
        ->andReturn($pendingMailFailing, $pendingMailSucceeding);

    $result = app(HealthEventNotificationService::class)
        ->notifyWebsite($website, 'heartbeat', 'danger', 'Returned HTTP 500.');

    expect($result)->toBeTrue();
    Log::shouldHaveReceived('error')->once();
});
