<?php

use App\Enums\WebsiteServicesEnum;
use App\Mail\HealthStatusAlert;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use App\Models\Website;
use App\Services\HealthEventNotificationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

test('command clears expired snooze on a website that is still unhealthy and re-fires the alert', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'current_status' => 'danger',
        'status_summary' => 'Returned HTTP 500.',
        'silenced_until' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $this->artisan('app:process-expired-snoozes')
        ->assertSuccessful();

    expect($website->refresh()->silenced_until)->toBeNull();

    Mail::assertSent(HealthStatusAlert::class, 1);
});

test('command clears expired snooze on an api monitor that is still unhealthy and re-fires the alert', function () {
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'current_status' => 'warning',
        'status_summary' => 'Latency exceeded threshold.',
        'silenced_until' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => WebsiteServicesEnum::API_MONITOR,
        ]);

    $this->artisan('app:process-expired-snoozes')
        ->assertSuccessful();

    expect($monitor->refresh()->silenced_until)->toBeNull();

    Mail::assertSent(HealthStatusAlert::class, 1);
});

test('command clears expired snooze without notifying when the monitor recovered to healthy', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'current_status' => 'healthy',
        'silenced_until' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $this->artisan('app:process-expired-snoozes')
        ->assertSuccessful();

    expect($website->refresh()->silenced_until)->toBeNull();

    Mail::assertNothingSent();
});

test('command leaves an active snooze untouched and sends no alert', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'current_status' => 'danger',
        'silenced_until' => now()->addHour(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $this->artisan('app:process-expired-snoozes')
        ->assertSuccessful();

    expect($website->refresh()->silenced_until)->not->toBeNull()
        ->and($website->silenced_until->isFuture())->toBeTrue();

    Mail::assertNothingSent();
});

test('command is idempotent and does not re-alert on subsequent runs', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'current_status' => 'danger',
        'status_summary' => 'Returned HTTP 503.',
        'silenced_until' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $this->artisan('app:process-expired-snoozes')->assertSuccessful();
    $this->artisan('app:process-expired-snoozes')->assertSuccessful();

    Mail::assertSent(HealthStatusAlert::class, 1);
});

test('command ignores monitors that were never snoozed', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'current_status' => 'danger',
        'silenced_until' => null,
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $this->artisan('app:process-expired-snoozes')
        ->assertSuccessful();

    Mail::assertNothingSent();
});

test('command preserves silenced_until when delivery throws so the next run retries', function () {
    Log::spy();

    $website = Website::factory()->create([
        'current_status' => 'danger',
        'status_summary' => 'Returned HTTP 500.',
        'silenced_until' => now()->subMinute(),
    ]);

    $this->mock(HealthEventNotificationService::class, function ($mock): void {
        $mock->shouldReceive('notifyWebsite')->once()->andThrow(new RuntimeException('SMTP down'));
    });

    $this->artisan('app:process-expired-snoozes')
        ->assertSuccessful();

    expect($website->refresh()->silenced_until)->not->toBeNull();

    Log::shouldHaveReceived('error')->once();
});

test('command preserves silenced_until when every channel fails so the next run retries', function () {
    Log::spy();

    $website = Website::factory()->create([
        'current_status' => 'danger',
        'status_summary' => 'Returned HTTP 500.',
        'silenced_until' => now()->subMinute(),
    ]);

    // Service swallows per-channel exceptions and returns false when every
    // attempted channel failed; the command must treat that as a retry signal.
    $this->mock(HealthEventNotificationService::class, function ($mock): void {
        $mock->shouldReceive('notifyWebsite')->once()->andReturn(false);
    });

    $this->artisan('app:process-expired-snoozes')
        ->assertSuccessful();

    expect($website->refresh()->silenced_until)->not->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});

test('command clears silenced_until when at least one channel delivered (partial success is success)', function () {
    Log::spy();

    $website = Website::factory()->create([
        'current_status' => 'danger',
        'status_summary' => 'Returned HTTP 500.',
        'silenced_until' => now()->subMinute(),
    ]);

    // Service returns true when at least one channel delivered — even if
    // others failed inside. The command MUST clear so we don't re-fire the
    // alert to channels that already received it.
    $this->mock(HealthEventNotificationService::class, function ($mock): void {
        $mock->shouldReceive('notifyWebsite')->once()->andReturn(true);
    });

    $this->artisan('app:process-expired-snoozes')
        ->assertSuccessful();

    expect($website->refresh()->silenced_until)->toBeNull();
});

test('command skips disabled api monitors but still clears their expired snooze', function () {
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'is_enabled' => false,
        'current_status' => 'danger',
        'silenced_until' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => WebsiteServicesEnum::API_MONITOR,
        ]);

    $this->artisan('app:process-expired-snoozes')
        ->assertSuccessful();

    expect($monitor->refresh()->silenced_until)->toBeNull();

    Mail::assertNothingSent();
});

test('command skips websites with every check toggled off but still clears their expired snooze', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'uptime_check' => false,
        'ssl_check' => false,
        'outbound_check' => false,
        'current_status' => 'danger',
        'silenced_until' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $this->artisan('app:process-expired-snoozes')
        ->assertSuccessful();

    expect($website->refresh()->silenced_until)->toBeNull();

    Mail::assertNothingSent();
});

test('command does not clobber a re-snooze that lands between selection and the per-row clear', function () {
    $website = Website::factory()->create([
        'current_status' => 'danger',
        'status_summary' => 'Returned HTTP 500.',
        'silenced_until' => now()->subMinute(),
    ]);

    $newSnoozeUntil = now()->addHour();

    $this->mock(HealthEventNotificationService::class, function ($mock) use ($website, $newSnoozeUntil): void {
        $mock->shouldReceive('notifyWebsite')->once()->andReturnUsing(
            function () use ($website, $newSnoozeUntil): void {
                Website::query()
                    ->whereKey($website->id)
                    ->update(['silenced_until' => $newSnoozeUntil]);
            }
        );
    });

    $this->artisan('app:process-expired-snoozes')->assertSuccessful();

    $fresh = $website->fresh();

    expect($fresh->silenced_until)->not->toBeNull()
        ->and($fresh->silenced_until->isFuture())->toBeTrue()
        ->and(now()->diffInMinutes($fresh->silenced_until))->toBeGreaterThan(50);
});
