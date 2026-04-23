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

test('command skips disabled package-managed api monitors when marking stale checks', function () {
    Mail::fake();

    $api = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'disabled-api-health',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'is_enabled' => false,
        'last_heartbeat_at' => now()->subMinutes(6),
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

    $api->refresh();

    expect($api->current_status)->toBe('healthy')
        ->and($api->stale_at)->toBeNull();

    $this->assertDatabaseMissing('monitor_api_results', [
        'monitor_api_id' => $api->id,
        'status' => 'danger',
    ]);

    Mail::assertNothingSent();
});
