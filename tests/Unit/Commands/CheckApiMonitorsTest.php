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

test('command treats matching expected 404 status as healthy', function () {
    Http::fake([
        '*' => Http::response(['message' => 'missing by design'], 404),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/missing',
        'expected_status' => 404,
        'data_path' => '',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('healthy')
        ->and($monitor->status_summary)->toBe('API heartbeat succeeded with HTTP status 404.')
        ->and($result?->is_success)->toBeTrue()
        ->and($result?->status)->toBe('healthy')
        ->and($result?->summary)->toBe('API heartbeat succeeded with HTTP status 404.');
});

test('command treats matching expected 404 with failed assertions as warning', function () {
    Http::fake([
        '*' => Http::response(['message' => 'missing by design'], 404),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/missing-with-assertion',
        'expected_status' => 404,
        'data_path' => 'data.status',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('warning')
        ->and($result?->is_success)->toBeFalse()
        ->and($result?->status)->toBe('warning');
});

test('command treats matching expected 404 with invalid json as warning', function () {
    Http::fake([
        '*' => Http::response('not-json', 404),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/malformed-missing',
        'expected_status' => 404,
        'data_path' => 'data.status',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('warning')
        ->and($result?->is_success)->toBeFalse()
        ->and($result?->status)->toBe('warning')
        ->and($result?->failed_assertions)->toContain([
            'path' => '_response_body',
            'type' => 'json_valid',
            'message' => 'Invalid JSON response: Syntax error',
        ]);
});

test('command treats matching expected 404 with literal null json body as warning', function () {
    Http::fake([
        '*' => Http::response('null', 404),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.com/null-body',
        'expected_status' => 404,
        'data_path' => 'data.status',
    ]);

    $this->artisan('monitor:check-apis')
        ->assertSuccessful();

    $monitor->refresh();
    $result = MonitorApiResult::where('monitor_api_id', $monitor->id)->latest()->first();

    expect($monitor->current_status)->toBe('warning')
        ->and($result?->is_success)->toBeFalse()
        ->and($result?->status)->toBe('warning')
        ->and(collect($result?->failed_assertions)->contains(
            fn (array $assertion): bool => ($assertion['path'] ?? null) === 'data.status'
                && ($assertion['message'] ?? null) === 'Value does not exist at path'
        ))->toBeTrue();
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

test('command sends notifications for failed manual api monitor regressions', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'manual-health',
        'url' => 'https://api.example.com/health',
        'source' => 'manual',
        'current_status' => 'healthy',
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

    expect($monitor->current_status)->toBe('warning');

    Mail::assertSent(HealthStatusAlert::class, 1);
});

test('command does not notify when a manual api monitor remains in the same failing status', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 200),
    ]);

    Mail::fake();

    $monitor = MonitorApis::factory()->create([
        'title' => 'manual-health',
        'url' => 'https://api.example.com/health',
        'source' => 'manual',
        'current_status' => 'warning',
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

    expect($monitor->current_status)->toBe('warning');

    Mail::assertNothingSent();
});
