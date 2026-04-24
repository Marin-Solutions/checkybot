<?php

use App\Support\ApiMonitorTestNotification;
use Filament\Notifications\Notification;

test('healthy result produces success notification with status code and response time', function () {
    $notification = ApiMonitorTestNotification::fromResult([
        'code' => 200,
        'response_time_ms' => 142,
        'assertions' => [],
    ], 200);

    expect($notification)->toBeInstanceOf(Notification::class);
    expect($notification->getTitle())->toBe('API response received');
    expect($notification->getBody())->toContain('HTTP 200');
    expect($notification->getBody())->toContain('142ms');
    expect($notification->getStatus())->toBe('success');
});

test('connection failure produces danger notification with error body', function () {
    $notification = ApiMonitorTestNotification::fromResult([
        'code' => 0,
        'error' => 'Connection timeout: boom',
        'response_time_ms' => 0,
        'assertions' => [],
    ], 200);

    expect($notification->getTitle())->toBe('API request failed');
    expect($notification->getBody())->toContain('Connection timeout: boom');
    expect($notification->getStatus())->toBe('danger');
});

test('server error produces danger notification', function () {
    $notification = ApiMonitorTestNotification::fromResult([
        'code' => 500,
        'response_time_ms' => 87,
        'assertions' => [[
            'path' => '_http_status',
            'type' => 'status_code',
            'passed' => false,
            'message' => 'Expected HTTP status 200, got 500.',
        ]],
    ], 200);

    expect($notification->getTitle())->toBe('API request failed');
    expect($notification->getStatus())->toBe('danger');
    expect($notification->getBody())->toContain('HTTP 500');
    expect($notification->getBody())->toContain('Expected HTTP status 200, got 500.');
});

test('failed data path assertion produces warning notification listing failures', function () {
    $notification = ApiMonitorTestNotification::fromResult([
        'code' => 200,
        'response_time_ms' => 210,
        'assertions' => [[
            'path' => 'data.status',
            'passed' => false,
            'message' => 'Value does not exist at path',
        ]],
    ], 200);

    expect($notification->getTitle())->toBe('Some API assertions failed');
    expect($notification->getStatus())->toBe('warning');
    expect($notification->getBody())->toContain('HTTP 200');
    expect($notification->getBody())->toContain('✗ Path: data.status');
    expect($notification->getBody())->toContain('Value does not exist at path');
});

test('status mismatch without assertion failures produces degraded warning', function () {
    $notification = ApiMonitorTestNotification::fromResult([
        'code' => 200,
        'response_time_ms' => 55,
        'assertions' => [[
            'path' => '_http_status',
            'type' => 'status_code',
            'passed' => false,
            'message' => 'Expected HTTP status 201, got 200.',
        ]],
    ], 201);

    expect($notification->getTitle())->toBe('API response is degraded');
    expect($notification->getStatus())->toBe('warning');
});
