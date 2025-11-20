<?php

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Illuminate\Support\Facades\Http;

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
