<?php

use App\Mail\HealthStatusAlert;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use App\Models\Website;
use Illuminate\Support\Facades\Mail;

test('deprecated package stale command does not mutate package managed check health', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
        'current_status' => 'pending',
        'last_heartbeat_at' => null,
    ]);

    $api = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-health',
        'package_interval' => '5m',
        'current_status' => 'pending',
        'last_heartbeat_at' => null,
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

    expect($website->fresh()->current_status)->toBe('pending')
        ->and($website->fresh()->stale_at)->toBeNull()
        ->and($api->fresh()->current_status)->toBe('pending')
        ->and($api->fresh()->stale_at)->toBeNull();

    assertDatabaseMissing('website_log_history', [
        'website_id' => $website->id,
        'status' => 'danger',
    ]);

    assertDatabaseMissing('monitor_api_results', [
        'monitor_api_id' => $api->id,
        'status' => 'danger',
    ]);

    Mail::assertNotSent(HealthStatusAlert::class);
});
