<?php

use App\Services\PackageHealthStatusService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

test('api status treats matching expected 404 as healthy', function () {
    $service = app(PackageHealthStatusService::class);

    $result = [
        'code' => 404,
        'assertions' => [],
    ];

    expect($service->apiStatusFromResult($result, 404))->toBe('healthy')
        ->and($service->summaryForApi($result, 404))->toBe('API heartbeat succeeded with HTTP status 404.');
});

test('api status treats matching expected status with failed assertions as warning', function () {
    $service = app(PackageHealthStatusService::class);

    $result = [
        'code' => 404,
        'assertions' => [
            [
                'passed' => false,
            ],
        ],
    ];

    expect($service->apiStatusFromResult($result, 404))->toBe('warning')
        ->and($service->summaryForApi($result, 404))->toBe('API heartbeat is degraded with HTTP status 404.');
});

test('api status treats non-matching expected 201 as warning', function () {
    $service = app(PackageHealthStatusService::class);

    $result = [
        'code' => 200,
        'assertions' => [],
    ];

    expect($service->apiStatusFromResult($result, 201))->toBe('warning')
        ->and($service->summaryForApi($result, 201))->toBe('API heartbeat is degraded with HTTP status 200.');
});

test('stale detection waits until after the exact interval boundary', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $service = app(PackageHealthStatusService::class);

    expect($service->isStale(now()->subMinutes(5), '5m'))->toBeFalse()
        ->and($service->isStale(now()->subMinutes(5)->subSecond(), '5m'))->toBeTrue();
});

test('ssl status treats certificates expired earlier today as danger', function () {
    Carbon::setTestNow('2026-04-24 17:00:00');

    $service = app(PackageHealthStatusService::class);

    expect($service->sslStatusFromExpiryDate(Carbon::parse('2026-04-24 09:00:00')))->toBe('danger')
        ->and($service->summaryForSsl(Carbon::parse('2026-04-24 09:00:00')))->toBe('SSL certificate expired today.');
});

test('combined website status uses the worst http or ssl state', function () {
    Carbon::setTestNow('2026-04-24 12:00:00');

    $service = app(PackageHealthStatusService::class);

    expect($service->websiteStatusFromHttpAndSsl(200, Carbon::parse('2026-04-23')))->toBe('danger')
        ->and($service->websiteStatusFromHttpAndSsl(200, Carbon::parse('2026-04-30')))->toBe('warning')
        ->and($service->websiteStatusFromHttpAndSsl(500, Carbon::parse('2026-04-30')))->toBe('danger')
        ->and($service->websiteStatusFromHttpAndSsl(404, Carbon::parse('2026-06-01')))->toBe('warning');
});

test('worst status logs unknown status values', function () {
    Log::spy();

    $service = app(PackageHealthStatusService::class);

    expect($service->worstStatus('healthy', 'degraded'))->toBe('warning');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Unknown package health status encountered.', [
            'status' => 'degraded',
        ]);
});

afterEach(function () {
    Carbon::setTestNow();
});
