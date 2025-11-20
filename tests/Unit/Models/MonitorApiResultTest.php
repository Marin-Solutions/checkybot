<?php

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;

test('monitor api result belongs to monitor api', function () {
    $monitor = MonitorApis::factory()->create();
    $result = MonitorApiResult::factory()->create(['monitor_api_id' => $monitor->id]);

    expect($result->monitorApi)->toBeInstanceOf(MonitorApis::class);
    expect($result->monitorApi->id)->toBe($monitor->id);
});

test('monitor api result can be successful', function () {
    $result = MonitorApiResult::factory()->successful()->create();

    expect($result->is_success)->toBeTrue();
    expect($result->http_code)->toBe(200);
    expect($result->failed_assertions)->toBeNull();
});

test('monitor api result can be failed', function () {
    $result = MonitorApiResult::factory()->failed()->create();

    expect($result->is_success)->toBeFalse();
    expect($result->http_code)->not->toBe(200);
    expect($result->failed_assertions)->not->toBeNull();
});

test('monitor api result casts is success to boolean', function () {
    $result = MonitorApiResult::factory()->create(['is_success' => 1]);

    expect($result->is_success)->toBeBool();
});

test('monitor api result casts response time to integer', function () {
    $result = MonitorApiResult::factory()->create(['response_time_ms' => '150']);

    expect($result->response_time_ms)->toBeInt();
    expect($result->response_time_ms)->toBe(150);
});

test('monitor api result casts http code to integer', function () {
    $result = MonitorApiResult::factory()->create(['http_code' => '200']);

    expect($result->http_code)->toBeInt();
    expect($result->http_code)->toBe(200);
});

test('monitor api result casts failed assertions to array', function () {
    $result = MonitorApiResult::factory()->create([
        'failed_assertions' => ['error' => 'Test failed'],
    ]);

    expect($result->failed_assertions)->toBeArray();
    expect($result->failed_assertions)->toBe(['error' => 'Test failed']);
});

test('monitor api result casts response body to array', function () {
    $result = MonitorApiResult::factory()->create([
        'response_body' => ['data' => ['status' => 'ok']],
    ]);

    expect($result->response_body)->toBeArray();
    expect($result->response_body)->toBe(['data' => ['status' => 'ok']]);
});

test('record result creates successful result', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $testResult = [
        'code' => 200,
        'body' => ['status' => 'ok'],
        'assertions' => [
            ['passed' => true, 'path' => 'status', 'message' => 'OK'],
        ],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);

    expect($result->is_success)->toBeTrue();
    expect($result->http_code)->toBe(200);
    expect($result->failed_assertions)->toBeEmpty();
    expect($result->response_time_ms)->toBeGreaterThanOrEqual(0);
});

test('record result creates failed result with assertions', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $testResult = [
        'code' => 500,
        'body' => ['status' => 'error'],
        'assertions' => [
            [
                'passed' => false,
                'path' => 'status',
                'type' => 'value_compare',
                'message' => 'Expected ok, got error',
            ],
        ],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);

    expect($result->is_success)->toBeFalse();
    expect($result->http_code)->toBe(500);
    expect($result->failed_assertions)->not->toBeEmpty();
    expect($result->failed_assertions)->toHaveCount(1);
    expect($result->failed_assertions[0]['path'])->toBe('status');
});

test('record result only saves response body on error', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $successResult = [
        'code' => 200,
        'body' => ['status' => 'ok'],
        'assertions' => [['passed' => true]],
    ];

    $result = MonitorApiResult::recordResult($monitor, $successResult, $startTime);
    expect($result->response_body)->toBeNull();

    $failedResult = [
        'code' => 500,
        'body' => ['status' => 'error'],
        'assertions' => [['passed' => false, 'message' => 'Failed']],
    ];

    $result = MonitorApiResult::recordResult($monitor, $failedResult, $startTime);
    expect($result->response_body)->not->toBeNull();
});

test('record result calculates response time', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true) - 0.15; // 150ms ago

    $testResult = [
        'code' => 200,
        'body' => [],
        'assertions' => [],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);

    expect($result->response_time_ms)->toBeGreaterThan(100);
    expect($result->response_time_ms)->toBeLessThan(200);
});
