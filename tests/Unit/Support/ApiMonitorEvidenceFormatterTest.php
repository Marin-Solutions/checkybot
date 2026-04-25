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

    expect($normalized[0]['actual'])->toBe('{"feature_a":true}')
        ->and($normalized[0]['expected'])->toBe('—');
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
        ->and(ApiMonitorEvidenceFormatter::httpCodeColor(404))->toBe('warning')
        ->and(ApiMonitorEvidenceFormatter::httpCodeColor(200))->toBe('success');
});

test('format as pre html escapes raw html content', function () {
    $html = ApiMonitorEvidenceFormatter::formatAsPreHtml('<script>alert(1)</script>')->toHtml();

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>');
});
