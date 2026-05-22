<?php

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Support\ApiMonitorEvidenceFormatter;

test('mask headers redacts token and cookie headers', function () {
    $masked = ApiMonitorEvidenceFormatter::maskHeaders([
        'X-Auth-Token' => 'secret-token',
        'Set-Cookie' => 'session=value',
        'Accept' => 'application/json',
    ]);

    expect($masked)->toBe([
        'X-Auth-Token' => '[redacted]',
        'Set-Cookie' => '[redacted]',
        'Accept' => 'application/json',
    ]);
});

test('format payload returns default empty value for null payload', function () {
    expect(ApiMonitorEvidenceFormatter::formatPayload(null, 'empty'))->toBe('empty');
});

test('format payload pretty prints arrays', function () {
    expect(ApiMonitorEvidenceFormatter::formatPayload(['status' => 'ok']))
        ->toBe("{\n    \"status\": \"ok\"\n}");
});

test('normalize assertions falls back to unknown path', function () {
    expect(ApiMonitorEvidenceFormatter::normalizeAssertions([
        ['type' => 'exists', 'message' => 'Missing value'],
    ]))->toBe([
        [
            'path' => 'Unknown path',
            'type' => 'exists',
            'message' => 'Missing value',
            'actual' => '—',
            'expected' => '—',
        ],
    ]);
});

test('normalize assertions surfaces actual and expected values', function () {
    $normalized = ApiMonitorEvidenceFormatter::normalizeAssertions([
        [
            'path' => 'status',
            'type' => 'value_compare',
            'message' => 'Value comparison failed: expected = active',
            'actual' => 'pending',
            'expected' => '= active',
        ],
    ]);

    expect($normalized)->toBe([
        [
            'path' => 'status',
            'type' => 'value_compare',
            'message' => 'Value comparison failed: expected = active',
            'actual' => 'pending',
            'expected' => '= active',
        ],
    ]);
});

test('normalize assertions renders genuine null actual as null string not em dash', function () {
    $normalized = ApiMonitorEvidenceFormatter::normalizeAssertions([
        [
            'path' => 'status',
            'type' => 'value_compare',
            'message' => 'Value comparison failed',
            'actual' => null,
            'expected' => '= active',
        ],
    ]);

    expect($normalized[0]['actual'])->toBe('null')
        ->and($normalized[0]['expected'])->toBe('= active');
});

test('normalize assertions falls back to em dash when actual key is absent', function () {
    $normalized = ApiMonitorEvidenceFormatter::normalizeAssertions([
        [
            'path' => 'status',
            'type' => 'exists',
            'message' => 'Legacy record without actual key',
        ],
    ]);

    expect($normalized[0]['actual'])->toBe('—')
        ->and($normalized[0]['expected'])->toBe('—');
});

test('normalize assertions stringifies non scalar actual and expected values', function () {
    $normalized = ApiMonitorEvidenceFormatter::normalizeAssertions([
        [
            'path' => 'flags',
            'type' => 'value_compare',
            'message' => 'Value comparison failed',
            'actual' => ['feature_a' => true],
            'expected' => null,
        ],
    ]);

    // `expected` is present with a null value → renders as the literal
    // string "null" so operators can tell it apart from a legacy record
    // where the key was absent (which renders as "—").
    expect($normalized[0]['actual'])->toBe('{"feature_a":true}')
        ->and($normalized[0]['expected'])->toBe('null');
});

test('stringify assertion value handles scalars and complex types', function () {
    expect(ApiMonitorEvidenceFormatter::stringifyAssertionValue(null))->toBe('null')
        ->and(ApiMonitorEvidenceFormatter::stringifyAssertionValue(true))->toBe('true')
        ->and(ApiMonitorEvidenceFormatter::stringifyAssertionValue(false))->toBe('false')
        ->and(ApiMonitorEvidenceFormatter::stringifyAssertionValue(42))->toBe('42')
        ->and(ApiMonitorEvidenceFormatter::stringifyAssertionValue(3.14))->toBe('3.14')
        ->and(ApiMonitorEvidenceFormatter::stringifyAssertionValue('text'))->toBe('text')
        ->and(ApiMonitorEvidenceFormatter::stringifyAssertionValue([1, 2]))->toBe('[1,2]');
});

test('status and http code colors follow monitor severity rules', function () {
    expect(ApiMonitorEvidenceFormatter::statusColor('danger'))->toBe('danger')
        ->and(ApiMonitorEvidenceFormatter::httpCodeColor(0))->toBe('danger')
        ->and(ApiMonitorEvidenceFormatter::httpCodeColor(404))->toBe('warning')
        ->and(ApiMonitorEvidenceFormatter::httpCodeColor(200))->toBe('success');
});

test('format as pre html escapes raw html content', function () {
    $html = ApiMonitorEvidenceFormatter::formatAsPreHtml('<script>alert(1)</script>')->toHtml();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});

test('replay template uses redacted header placeholders and current monitor config', function () {
    $monitor = MonitorApis::factory()->create([
        'http_method' => 'POST',
        'url' => 'https://api.example.test/orders?api_key=secret&status=open',
        'request_body_type' => 'json',
        'request_body' => '{"email":"monitor@example.com","password":"secret"}',
    ]);

    $result = MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'request_headers' => [
            'Authorization' => '[redacted]',
            'X-API-Key' => '[redacted]',
            'Cookie' => '[redacted]',
            'Accept' => 'application/json',
        ],
    ]);

    $command = ApiMonitorEvidenceFormatter::replayTemplate($result);

    expect($command)
        ->toContain("curl --request 'POST'")
        ->toContain("--url 'https://api.example.test/orders?api_key=%3CREPLACE_API_KEY%3E&status=open'")
        ->toContain("--header 'Authorization: <REPLACE_AUTHORIZATION>'")
        ->toContain("--header 'X-API-Key: <REPLACE_X_API_KEY>'")
        ->toContain("--header 'Cookie: <REPLACE_COOKIE>'")
        ->toContain("--header 'Accept: application/json'")
        ->toContain('"password":"[redacted]"')
        ->not->toContain('Bearer secret')
        ->not->toContain('"password":"secret"')
        ->not->toContain('api_key=secret');
});

test('replay template redacts form body tokens and omits raw bodies', function () {
    $formMonitor = MonitorApis::factory()->create([
        'http_method' => 'PATCH',
        'url' => 'https://api.example.test/token',
        'request_body_type' => 'form',
        'request_body' => '{"grant_type":"client_credentials","access_token":"secret-token"}',
    ]);
    $formResult = MonitorApiResult::factory()->create([
        'monitor_api_id' => $formMonitor->id,
        'request_headers' => ['Accept' => 'application/json'],
    ]);

    $rawMonitor = MonitorApis::factory()->create([
        'http_method' => 'POST',
        'url' => 'https://api.example.test/raw',
        'request_body_type' => 'raw',
        'request_body' => 'token=secret-token',
    ]);
    $rawResult = MonitorApiResult::factory()->create([
        'monitor_api_id' => $rawMonitor->id,
        'request_headers' => ['Accept' => 'application/json'],
    ]);

    expect(ApiMonitorEvidenceFormatter::replayTemplate($formResult))
        ->toContain('access_token=%5Bredacted%5D')
        ->not->toContain('secret-token');

    expect(ApiMonitorEvidenceFormatter::replayTemplate($rawResult))
        ->not->toContain('--data-raw')
        ->not->toContain('secret-token');
});
