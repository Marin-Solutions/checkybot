<?php

use App\Services\PackageHealthStatusService;

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
