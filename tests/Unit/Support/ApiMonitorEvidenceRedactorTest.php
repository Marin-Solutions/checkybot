<?php

use App\Support\ApiMonitorEvidenceRedactor;

test('response body redaction preserves numeric cookie metrics', function () {
    $redacted = ApiMonitorEvidenceRedactor::redactSavedResponseBody([
        'active_cookies' => 3,
        'blocked_cookie_count' => 1,
        'cookie' => 12345,
        'cookie_header' => 'session=secret',
        'set_cookie' => 'session=response-secret',
        'nested' => [
            'third_party_cookies' => 2,
            'cookie_value' => 'value-secret',
        ],
    ]);

    expect($redacted)->toBe([
        'active_cookies' => 3,
        'blocked_cookie_count' => 1,
        'cookie' => '[redacted]',
        'cookie_header' => '[redacted]',
        'set_cookie' => '[redacted]',
        'nested' => [
            'third_party_cookies' => 2,
            'cookie_value' => '[redacted]',
        ],
    ]);
});

test('header redaction does not expose numeric cookie values', function () {
    expect(ApiMonitorEvidenceRedactor::redactHeaders([
        'Cookie' => 12345,
        'Set-Cookie' => 'session=response-secret',
        'X-Active-Cookies' => 3,
        'Accept' => 'application/json',
    ]))->toBe([
        'Cookie' => '[redacted]',
        'Set-Cookie' => '[redacted]',
        'X-Active-Cookies' => '[redacted]',
        'Accept' => 'application/json',
    ]);
});
