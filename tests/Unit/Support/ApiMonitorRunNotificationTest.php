<?php

use App\Enums\RunSource;
use App\Models\MonitorApiResult;
use App\Support\ApiMonitorRunNotification;
use Filament\Notifications\Notification;

test('healthy on demand result produces success run history notification', function () {
    $result = MonitorApiResult::factory()->create([
        'status' => 'healthy',
        'http_code' => 200,
        'response_time_ms' => 142,
        'failed_assertions' => [],
        'summary' => 'API heartbeat succeeded with HTTP status 200.',
        'run_source' => RunSource::OnDemand,
        'is_on_demand' => true,
    ]);

    $notification = ApiMonitorRunNotification::fromOutcome([
        'result' => $result,
        'status' => 'healthy',
        'summary' => 'API heartbeat succeeded with HTTP status 200.',
        'previous_status' => null,
    ]);

    expect($notification)->toBeInstanceOf(Notification::class)
        ->and($notification->getTitle())->toBe('On-demand run succeeded')
        ->and($notification->getStatus())->toBe('success')
        ->and($notification->getBody())->toContain('Recorded to run history.')
        ->and($notification->getBody())->toContain('HTTP 200')
        ->and($notification->getBody())->toContain('142ms')
        ->and($notification->getBody())->toContain('API heartbeat succeeded with HTTP status 200.');
});

test('failed on demand result produces danger notification with assertion count', function () {
    $result = MonitorApiResult::factory()->failed()->onDemand()->create([
        'http_code' => 500,
        'response_time_ms' => 987,
        'failed_assertions' => [
            ['path' => '_http_status', 'type' => 'status_code', 'message' => 'Expected HTTP status 200, got 500.'],
            ['path' => 'data.status', 'type' => 'exists', 'message' => 'Value does not exist at path.'],
        ],
    ]);

    $notification = ApiMonitorRunNotification::fromOutcome([
        'result' => $result,
        'status' => 'danger',
        'summary' => 'API heartbeat failed with HTTP status 500.',
        'previous_status' => 'healthy',
    ]);

    expect($notification->getTitle())->toBe('On-demand run failed')
        ->and($notification->getStatus())->toBe('danger')
        ->and($notification->getBody())->toContain('HTTP 500')
        ->and($notification->getBody())->toContain('987ms')
        ->and($notification->getBody())->toContain('2 assertions failed.')
        ->and($notification->getBody())->toContain('API heartbeat failed with HTTP status 500.');
});

test('run notification escapes summary html', function () {
    $result = MonitorApiResult::factory()->onDemand()->create([
        'status' => 'warning',
        'http_code' => 200,
        'response_time_ms' => 55,
        'failed_assertions' => [['path' => 'data.status', 'message' => 'Missing status.']],
    ]);

    $notification = ApiMonitorRunNotification::fromOutcome([
        'result' => $result,
        'status' => 'warning',
        'summary' => '<script>alert(1)</script>',
        'previous_status' => 'healthy',
    ]);

    expect($notification->getTitle())->toBe('On-demand run is degraded')
        ->and($notification->getStatus())->toBe('warning')
        ->and($notification->getBody())->not->toContain('<script>')
        ->and($notification->getBody())->toContain('&lt;script&gt;');
});
