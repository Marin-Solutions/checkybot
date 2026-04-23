<?php

use App\Mail\HealthStatusAlert;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use App\Models\Website;
use Illuminate\Support\Facades\Mail;

test('command marks overdue package-managed checks as stale danger and notifies once', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    $api = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-health',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $api->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    $website->refresh();
    $api->refresh();

    expect($website->current_status)->toBe('danger');
    expect($website->stale_at)->not->toBeNull();
    expect($api->current_status)->toBe('danger');
    expect($api->stale_at)->not->toBeNull();

    Mail::assertSent(HealthStatusAlert::class, 2);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    Mail::assertSent(HealthStatusAlert::class, 2);
});

test('command treats scheduler style package intervals as stale thresholds', function () {
    $website = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => 'every_5_minutes',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    $api = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-health',
        'package_interval' => 'every_5_minutes',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    expect($website->fresh()->stale_at)->not->toBeNull()
        ->and($api->fresh()->stale_at)->not->toBeNull()
        ->and($website->fresh()->status_summary)->toContain('5m')
        ->and($api->fresh()->status_summary)->toContain('5m');
});
