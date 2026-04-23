<?php

use App\Mail\HealthStatusAlert;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

test('command checks all active api monitors', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/health',
        'data_path' => 'data.status',
    ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
    ]);
});

test('command skips disabled api monitors', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->disabled()->create([
        'url' => 'https://api.example.com/disabled-health',
        'current_status' => 'unknown',
        'last_heartbeat_at' => null,
        'stale_at' => null,
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    assertDatabaseMissing('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
    ]);
    Http::assertNothingSent();

    $monitor->refresh();

    expect($monitor->current_status)->toBe('unknown');
    expect($monitor->last_heartbeat_at)->toBeNull();
});

test('command records failed checks', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 500),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/health',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
        'is_success' => false,
    ]);
});

test('command validates assertions', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    $monitor = MonitorApis::factory()->create();

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($result->is_success)->toBeTrue();
});

test('command records warning status history and notifies for package-managed assertion failures', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'package-health',
        'url' => 'https://api.example.com/health',
        'source' => 'package',
        'package_name' => 'package-health',
        'package_interval' => '5m',
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $monitor->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::API_MONITOR,
        ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'expected_value' => 'ok',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('warning');
    expect($monitor->last_heartbeat_at)->not->toBeNull();
    expect($result?->status)->toBe('warning');

    Mail::assertSent(HealthStatusAlert::class);
});
