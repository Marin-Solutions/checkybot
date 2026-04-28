<?php

use App\Enums\RunSource;
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

test('monitor api result tracks diagnostic run source', function () {
    $result = MonitorApiResult::factory()->onDemand()->create();

    expect($result->run_source)->toBe(RunSource::OnDemand)
        ->and($result->is_on_demand)->toBeTrue();
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

test('monitor api result casts request and response headers to arrays', function () {
    $result = MonitorApiResult::factory()->create([
        'request_headers' => ['Authorization' => '[redacted]'],
        'response_headers' => ['content-type' => 'application/json'],
    ]);

    expect($result->request_headers)->toBe(['Authorization' => '[redacted]'])
        ->and($result->response_headers)->toBe(['content-type' => 'application/json']);
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

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime, runSource: RunSource::OnDemand);

    expect($result->is_success)->toBeTrue();
    expect($result->http_code)->toBe(200);
    expect($result->failed_assertions)->toBeEmpty();
    expect($result->response_time_ms)->toBeGreaterThanOrEqual(0);
    expect($result->run_source)->toBe(RunSource::OnDemand);
    expect($result->is_on_demand)->toBeTrue();
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

test('record result stores header snapshots for run evidence', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $testResult = [
        'code' => 500,
        'body' => ['status' => 'error'],
        'assertions' => [],
        'request_headers' => ['Authorization' => '[redacted]'],
        'response_headers' => ['content-type' => 'application/json'],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime, 'danger', 'API heartbeat failed with HTTP status 500.');

    expect($result->request_headers)->toBe(['Authorization' => '[redacted]'])
        ->and($result->response_headers)->toBe(['content-type' => 'application/json']);
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

test('record result preserves raw failure payloads when json parsing fails', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $failedResult = [
        'code' => 500,
        'body' => null,
        'raw_body' => '<html>upstream exploded</html>',
        'assertions' => [['passed' => false, 'message' => 'Invalid JSON response']],
        'error' => 'Invalid JSON response: Syntax error',
    ];

    $result = MonitorApiResult::recordResult($monitor, $failedResult, $startTime, 'danger', 'API heartbeat failed with HTTP status 500.');

    expect($result->response_body)->toBe([
        MonitorApiResult::RAW_BODY_KEY => '<html>upstream exploded</html>',
        'error' => 'Invalid JSON response: Syntax error',
    ]);
});

test('record result uses internal metadata key for error-only failures', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $failedResult = [
        'code' => 0,
        'body' => null,
        'raw_body' => null,
        'assertions' => [],
        'error' => 'Connection timeout: cURL error 28',
    ];

    $result = MonitorApiResult::recordResult($monitor, $failedResult, $startTime, 'danger', 'API request failed.');

    expect($result->response_body)->toBe([
        MonitorApiResult::ERROR_METADATA_KEY => 'Connection timeout: cURL error 28',
    ]);
});

test('response body attribute preserves invalid utf8 payload data instead of dropping it', function () {
    $result = MonitorApiResult::factory()->create([
        'response_body' => ['raw_body' => "bad\xB1value"],
    ]);

    expect($result->getRawOriginal('response_body'))->toContain('bad�value')
        ->and($result->response_body)->toBe(['raw_body' => 'bad�value']);
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

test('record result persists actual and expected values for failed assertions', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $testResult = [
        'code' => 200,
        'body' => ['status' => 'pending'],
        'assertions' => [
            [
                'passed' => false,
                'path' => 'status',
                'type' => 'value_compare',
                'message' => 'Value comparison failed: expected = active',
                'actual' => 'pending',
                'expected' => '= active',
            ],
        ],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);

    expect($result->failed_assertions)->toHaveCount(1)
        ->and($result->failed_assertions[0]['actual'])->toBe('pending')
        ->and($result->failed_assertions[0]['expected'])->toBe('= active')
        ->and($result->failed_assertions[0]['message'])->toBe('Value comparison failed: expected = active');
});

test('record result truncates oversized actual payloads to keep failed_assertions bounded', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $largePayload = ['blob' => str_repeat('x', 5000)];

    $testResult = [
        'code' => 200,
        'body' => $largePayload,
        'assertions' => [
            [
                'passed' => false,
                'path' => 'blob',
                'type' => 'value_compare',
                'message' => 'Value comparison failed',
                'actual' => $largePayload,
                'expected' => '= small',
            ],
        ],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);
    $persistedActual = $result->failed_assertions[0]['actual'];

    expect($persistedActual)->toBeString()
        ->and(strlen($persistedActual))->toBeLessThanOrEqual(1100)
        ->and($persistedActual)->toEndWith('… (truncated)')
        ->and($result->failed_assertions[0]['expected'])->toBe('= small');
});

test('record result truncates oversized multibyte string actuals without splitting codepoints', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    // Three-byte UTF-8 codepoint repeated past the 1000-character cap. If
    // truncation falls back to byte-based substr it can leave a half-encoded
    // codepoint that breaks JSON encoding; mb_substr keeps the result valid.
    $multibytePayload = str_repeat('日', 1500);

    $testResult = [
        'code' => 200,
        'body' => null,
        'assertions' => [
            [
                'passed' => false,
                'path' => 'message',
                'type' => 'value_compare',
                'message' => 'Value comparison failed',
                'actual' => $multibytePayload,
                'expected' => '= 日',
            ],
        ],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);
    $persistedActual = $result->failed_assertions[0]['actual'];

    expect($persistedActual)->toBeString()
        ->and(mb_check_encoding($persistedActual, 'UTF-8'))->toBeTrue()
        ->and(mb_strlen($persistedActual, 'UTF-8'))->toBeLessThanOrEqual(1100)
        ->and($persistedActual)->toEndWith('… (truncated)');
});

test('record result preserves scalar actual values without stringifying them', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $testResult = [
        'code' => 500,
        'body' => null,
        'assertions' => [
            [
                'passed' => false,
                'path' => '_http_status',
                'type' => 'status_code',
                'message' => 'Expected HTTP status 200, got 500.',
                'actual' => 500,
                'expected' => 200,
            ],
        ],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);

    expect($result->failed_assertions[0]['actual'])->toBe(500)
        ->and($result->failed_assertions[0]['expected'])->toBe(200);
});

test('record result omits actual and expected keys when source assertion did not provide them', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $testResult = [
        'code' => 500,
        'body' => null,
        'assertions' => [
            [
                'passed' => false,
                'path' => 'status',
                'type' => 'exists',
                'message' => 'Value does not exist at path',
            ],
        ],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);

    // Keys must be absent (not null) so the evidence formatter renders
    // "—" rather than the literal string "null" for assertions that
    // never had comparison semantics in the first place.
    expect($result->failed_assertions)->toHaveCount(1)
        ->and($result->failed_assertions[0])->not->toHaveKey('actual')
        ->and($result->failed_assertions[0])->not->toHaveKey('expected');
});

test('record result preserves a genuine null actual when the source assertion provided one', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $testResult = [
        'code' => 200,
        'body' => ['status' => null],
        'assertions' => [
            [
                'passed' => false,
                'path' => 'status',
                'type' => 'value_compare',
                'message' => 'Value comparison failed: expected = active',
                'actual' => null,
                'expected' => '= active',
            ],
        ],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime);

    expect($result->failed_assertions[0])->toHaveKey('actual')
        ->and($result->failed_assertions[0]['actual'])->toBeNull()
        ->and($result->failed_assertions[0]['expected'])->toBe('= active');
});

test('record result treats healthy expected 404 status as success when status is provided', function () {
    $monitor = MonitorApis::factory()->create();
    $startTime = microtime(true);

    $testResult = [
        'code' => 404,
        'body' => ['message' => 'missing by design'],
        'assertions' => [],
    ];

    $result = MonitorApiResult::recordResult($monitor, $testResult, $startTime, 'healthy', 'API heartbeat succeeded with HTTP status 404.');

    expect($result->is_success)->toBeTrue()
        ->and($result->status)->toBe('healthy')
        ->and($result->http_code)->toBe(404);
});
