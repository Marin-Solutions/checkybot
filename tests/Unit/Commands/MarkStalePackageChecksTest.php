<?php

use App\Mail\HealthStatusAlert;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use App\Models\Website;
use Illuminate\Support\Carbon;
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

test('command treats zero-padded package intervals as stale thresholds', function () {
    $website = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '05m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    $api = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-health',
        'package_interval' => 'every_05_minutes',
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

test('command skips all-zero package intervals', function () {
    $website = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '00m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    $api = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-health',
        'package_interval' => 'every_00_minutes',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    expect($website->fresh()->stale_at)->toBeNull()
        ->and($api->fresh()->stale_at)->toBeNull();
});

test('command skips oversized package intervals without aborting valid stale checks', function () {
    $oversizedWebsite = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage-oversized',
        'package_interval' => '5000000000m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    $oversizedApi = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-oversized',
        'package_interval' => 'every_5000000000_minutes',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    $validWebsite = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage-valid',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    expect($oversizedWebsite->fresh()->stale_at)->toBeNull()
        ->and($oversizedApi->fresh()->stale_at)->toBeNull()
        ->and($validWebsite->fresh()->stale_at)->not->toBeNull();
});

test('command skips invalid legacy intervals without aborting valid stale checks', function () {
    $invalidWebsite = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'invalid-homepage',
        'package_interval' => 'every friday',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(30),
    ]);

    $validApi = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-health',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
    ]);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    expect($invalidWebsite->fresh()->stale_at)->toBeNull()
        ->and($validApi->fresh()->stale_at)->not->toBeNull();
});

test('command skips disabled package-managed api checks when marking stale', function () {
    Mail::fake();

    $api = MonitorApis::factory()->disabled()->create([
        'source' => 'package',
        'package_name' => 'disabled-api-health',
        'package_interval' => '5m',
        'current_status' => 'unknown',
        'last_heartbeat_at' => now()->subMinutes(6),
        'stale_at' => null,
        'status_summary' => null,
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

    expect($api->current_status)->toBe('unknown');
    expect($api->stale_at)->toBeNull();
    expect($api->status_summary)->toBeNull();

    assertDatabaseMissing('monitor_api_results', [
        'monitor_api_id' => $api->id,
        'status' => 'danger',
        'summary' => 'Heartbeat overdue. Expected every 5 minutes.',
    ]);

    Mail::assertNothingSent();
});

test('command skips package-managed websites when uptime and ssl checks are disabled', function () {
    Mail::fake();

    $website = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'disabled-homepage',
        'package_interval' => '5m',
        'uptime_check' => false,
        'ssl_check' => false,
        'current_status' => 'unknown',
        'last_heartbeat_at' => now()->subMinutes(6),
        'stale_at' => null,
        'status_summary' => null,
    ]);

    NotificationSetting::factory()
        ->websiteScope()
        ->email()
        ->create([
            'user_id' => $website->created_by,
            'website_id' => $website->id,
        ]);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    $website->refresh();

    expect($website->current_status)->toBe('unknown');
    expect($website->stale_at)->toBeNull();
    expect($website->status_summary)->toBeNull();

    assertDatabaseMissing('website_log_history', [
        'website_id' => $website->id,
        'status' => 'danger',
        'summary' => 'Heartbeat overdue. Expected every 5 minutes.',
    ]);

    Mail::assertNothingSent();
});

test('command only marks checks after the package interval is overdue', function () {
    $this->travelTo(Carbon::parse('2026-05-06 12:00:00'));

    $boundaryWebsite = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage-boundary',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(5),
    ]);

    $overdueWebsite = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage-overdue',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(5)->subSecond(),
    ]);

    $boundaryApi = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-boundary',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(5),
    ]);

    $overdueApi = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-overdue',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(5)->subSecond(),
    ]);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    expect($boundaryWebsite->fresh()->stale_at)->toBeNull()
        ->and($boundaryApi->fresh()->stale_at)->toBeNull()
        ->and($overdueWebsite->fresh()->stale_at)->not->toBeNull()
        ->and($overdueApi->fresh()->stale_at)->not->toBeNull();
});

test('command uses created at as the first-run stale threshold for never-run package checks', function () {
    $this->travelTo(Carbon::parse('2026-05-06 12:00:00'));

    $awaitingWebsite = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage-awaiting-first-run',
        'package_interval' => '5m',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'created_at' => now()->subMinutes(5),
    ]);

    $overdueWebsite = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage-never-ran',
        'package_interval' => '5m',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'created_at' => now()->subMinutes(5)->subSecond(),
    ]);

    $awaitingApi = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-awaiting-first-run',
        'package_interval' => '5m',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'created_at' => now()->subMinutes(5),
    ]);

    $overdueApi = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-never-ran',
        'package_interval' => '5m',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'created_at' => now()->subMinutes(5)->subSecond(),
    ]);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    expect($awaitingWebsite->fresh()->stale_at)->toBeNull()
        ->and($awaitingApi->fresh()->stale_at)->toBeNull()
        ->and($overdueWebsite->fresh()->current_status)->toBe('danger')
        ->and($overdueWebsite->fresh()->stale_at)->not->toBeNull()
        ->and($overdueApi->fresh()->current_status)->toBe('danger')
        ->and($overdueApi->fresh()->stale_at)->not->toBeNull();

    assertDatabaseHas('website_log_history', [
        'website_id' => $overdueWebsite->id,
        'status' => 'danger',
        'summary' => 'No heartbeat received within the expected 5m interval.',
    ]);

    assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $overdueApi->id,
        'status' => 'danger',
        'summary' => 'No scheduled API check completed within the expected 5m interval.',
    ]);
});

test('command uses awaiting heartbeat reset time before created at for reset package checks', function () {
    $this->travelTo(Carbon::parse('2026-05-06 12:00:00'));

    $recentlyResetWebsite = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage-reset',
        'package_interval' => '5m',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'awaiting_heartbeat_since' => now()->subMinutes(2),
        'created_at' => now()->subHour(),
    ]);

    $overdueResetWebsite = Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage-reset-overdue',
        'package_interval' => '5m',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'awaiting_heartbeat_since' => now()->subMinutes(5)->subSecond(),
        'created_at' => now()->subHour(),
    ]);

    $recentlyResetApi = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-reset',
        'package_interval' => '5m',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'awaiting_heartbeat_since' => now()->subMinutes(2),
        'created_at' => now()->subHour(),
    ]);

    $overdueResetApi = MonitorApis::factory()->create([
        'source' => 'package',
        'package_name' => 'api-reset-overdue',
        'package_interval' => '5m',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'awaiting_heartbeat_since' => now()->subMinutes(5)->subSecond(),
        'created_at' => now()->subHour(),
    ]);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    expect($recentlyResetWebsite->fresh()->stale_at)->toBeNull()
        ->and($recentlyResetApi->fresh()->stale_at)->toBeNull()
        ->and($overdueResetWebsite->fresh()->stale_at)->not->toBeNull()
        ->and($overdueResetApi->fresh()->stale_at)->not->toBeNull();
});

test('command processes overdue package websites beyond one chunk', function () {
    Website::factory()
        ->count(501)
        ->create([
            'source' => 'package',
            'package_name' => 'homepage',
            'package_interval' => '5m',
            'current_status' => 'healthy',
            'last_heartbeat_at' => now()->subMinutes(6),
        ]);

    Website::factory()->create([
        'source' => 'package',
        'package_name' => 'homepage-fresh',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now(),
    ]);

    $this->artisan('app:mark-stale-package-checks')
        ->assertSuccessful();

    expect(Website::query()->where('source', 'package')->whereNotNull('stale_at')->count())->toBe(501)
        ->and(Website::query()->where('package_name', 'homepage-fresh')->value('stale_at'))->toBeNull();
});
