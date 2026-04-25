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

test('command skips websites with uptime_check disabled even when ssl and outbound checks are on', function () {
    Mail::fake();

    // ssl/outbound checks don't refresh current_status — only uptime does —
    // so the stored "danger" is frozen from before the toggle change. The
    // command must not re-fire an alert from that stale state.
    $website = Website::factory()->create([
        'uptime_check' => false,
        'ssl_check' => true,
        'outbound_check' => true,
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

test('command alerts on a package-managed website with uptime_check off because MarkStalePackageChecks keeps current_status fresh', function () {
    Mail::fake();

    // syncSslChecks() creates package SSL websites with uptime_check=false.
    // LogUptimeSslJob never runs for them, but MarkStalePackageChecks does
    // and writes current_status='danger' on staleness — so a snooze that
    // expires while one is unhealthy must still re-fire the alert.
    $website = Website::factory()->create([
        'source' => 'package',
        'package_interval' => '5m',
        'uptime_check' => false,
        'current_status' => 'danger',
        'status_summary' => 'No heartbeat received in 5m.',
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

test('command skips snooze_expired alert if uptime_check was toggled off between fresh-fetch and delivery', function () {
    Mail::fake();

    // Operator disables uptime_check in the small window between the
    // command's fresh() probe and the helper's authoritative re-read.
    // The helper must observe the toggle and skip the alert; status would
    // be frozen from before the toggle so paging is misleading.
    $website = Website::factory()->create([
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

    $retrievals = 0;
    \Illuminate\Support\Facades\Event::listen(
        'eloquent.retrieved: '.Website::class,
        function (Website $retrieved) use (&$retrievals, $website): void {
            $retrievals++;

            if ($retrievals === 2 && $retrieved->id === $website->id) {
                Website::query()
                    ->whereKey($website->id)
                    ->update(['uptime_check' => false]);
            }
        }
    );

    try {
        $this->artisan('app:process-expired-snoozes')->assertSuccessful();
    } finally {
        \Illuminate\Support\Facades\Event::forget('eloquent.retrieved: '.Website::class);
    }

    expect($website->refresh()->silenced_until)->toBeNull();
    Mail::assertNothingSent();
});

test('command skips snooze_expired alert if an api monitor was disabled between fresh-fetch and delivery', function () {
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'is_enabled' => true,
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

    $retrievals = 0;
    \Illuminate\Support\Facades\Event::listen(
        'eloquent.retrieved: '.MonitorApis::class,
        function (MonitorApis $retrieved) use (&$retrievals, $monitor): void {
            $retrievals++;

            if ($retrievals === 2 && $retrieved->id === $monitor->id) {
                MonitorApis::query()
                    ->whereKey($monitor->id)
                    ->update(['is_enabled' => false]);
            }
        }
    );

    try {
        $this->artisan('app:process-expired-snoozes')->assertSuccessful();
    } finally {
        \Illuminate\Support\Facades\Event::forget('eloquent.retrieved: '.MonitorApis::class);
    }

    expect($monitor->refresh()->silenced_until)->toBeNull();
    Mail::assertNothingSent();
});

test('command skips snooze_expired alert when the monitor recovered concurrently before delivery', function () {
    Mail::fake();

    // The status is unhealthy at fresh-fetch time but flips to healthy
    // before deliverIfStillAlertable issues its second probe — simulating
    // a concurrent LogUptimeSslJob landing in the millisecond window. The
    // command must not page about a now-healthy monitor.
    $website = Website::factory()->create([
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

    // Mutate the DB on the SECOND retrieval (the fresh() call inside the
    // command) so the helper's third retrieval observes the recovery.
    // This forces the test to exercise deliverIfStillAlertable specifically
    // rather than short-circuiting at the fresh-fetch shouldAlert check.
    $retrievals = 0;
    \Illuminate\Support\Facades\Event::listen(
        'eloquent.retrieved: '.Website::class,
        function (Website $retrieved) use (&$retrievals, $website): void {
            $retrievals++;

            if ($retrievals === 2 && $retrieved->id === $website->id) {
                Website::query()
                    ->whereKey($website->id)
                    ->update(['current_status' => 'healthy']);
            }
        }
    );

    try {
        $this->artisan('app:process-expired-snoozes')->assertSuccessful();
    } finally {
        \Illuminate\Support\Facades\Event::forget('eloquent.retrieved: '.Website::class);
    }

    expect($website->refresh()->silenced_until)->toBeNull();

    Mail::assertNothingSent();
});

test('command skips snooze_expired alert when the api monitor recovered concurrently before delivery', function () {
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'is_enabled' => true,
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

    $retrievals = 0;
    \Illuminate\Support\Facades\Event::listen(
        'eloquent.retrieved: '.MonitorApis::class,
        function (MonitorApis $retrieved) use (&$retrievals, $monitor): void {
            $retrievals++;

            if ($retrievals === 2 && $retrieved->id === $monitor->id) {
                MonitorApis::query()
                    ->whereKey($monitor->id)
                    ->update(['current_status' => 'healthy']);
            }
        }
    );

    try {
        $this->artisan('app:process-expired-snoozes')->assertSuccessful();
    } finally {
        \Illuminate\Support\Facades\Event::forget('eloquent.retrieved: '.MonitorApis::class);
    }

    expect($monitor->refresh()->silenced_until)->toBeNull();
    Mail::assertNothingSent();
});

test('command does not clobber a re-snooze that lands between selection and the per-row clear', function () {
    Mail::fake();

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

test('command does not clobber an api monitor re-snooze that lands between selection and the per-row clear', function () {
    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'is_enabled' => true,
        'current_status' => 'danger',
        'status_summary' => 'Returned HTTP 500.',
        'silenced_until' => now()->subMinute(),
    ]);

    $newSnoozeUntil = now()->addHour();

    $this->mock(HealthEventNotificationService::class, function ($mock) use ($monitor, $newSnoozeUntil): void {
        $mock->shouldReceive('notifyApi')->once()->andReturnUsing(
            function () use ($monitor, $newSnoozeUntil): void {
                MonitorApis::query()
                    ->whereKey($monitor->id)
                    ->update(['silenced_until' => $newSnoozeUntil]);
            }
        );
    });

    $this->artisan('app:process-expired-snoozes')->assertSuccessful();

    $fresh = $monitor->fresh();

    expect($fresh->silenced_until)->not->toBeNull()
        ->and($fresh->silenced_until->isFuture())->toBeTrue()
        ->and(now()->diffInMinutes($fresh->silenced_until))->toBeGreaterThan(50);
});
