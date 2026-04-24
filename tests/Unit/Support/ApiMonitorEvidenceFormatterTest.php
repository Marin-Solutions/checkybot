<?php

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
        ],
    ]);
});

test('status and http code colors follow monitor severity rules', function () {
    expect(ApiMonitorEvidenceFormatter::statusColor('danger'))->toBe('danger')
        ->and(ApiMonitorEvidenceFormatter::httpCodeColor(404))->toBe('warning')
        ->and(ApiMonitorEvidenceFormatter::httpCodeColor(200))->toBe('success');
});
