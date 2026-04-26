<?php

use App\Models\WebsiteLogHistory;

test('website log history has correct table name', function () {
    $log = new WebsiteLogHistory;

    expect($log->getTable())->toBe('website_log_history');
});

test('website log history has fillable attributes', function () {
    $website = \App\Models\Website::factory()->create();
    $log = WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'ssl_expiry_date' => now()->addMonths(3),
        'http_status_code' => 200,
        'speed' => 350,
        'transport_error_type' => 'dns',
        'transport_error_message' => 'cURL error 6: Could not resolve host: example.invalid',
        'transport_error_code' => 6,
    ]);

    expect($log->website_id)->toBe($website->id);
    expect($log->ssl_expiry_date)->not->toBeNull();
    expect($log->http_status_code)->toBe(200);
    expect($log->speed)->toBe(350);
    expect($log->transport_error_type)->toBe('dns');
    expect($log->transport_error_message)->toContain('Could not resolve host');
    expect($log->transport_error_code)->toBe(6);
});

test('website log history records successful response', function () {
    $log = WebsiteLogHistory::factory()->create([
        'http_status_code' => 200,
    ]);

    expect($log->http_status_code)->toBe(200);
});

test('website log history records error response', function () {
    $log = WebsiteLogHistory::factory()->error()->create();

    expect($log->http_status_code)->not->toBe(200);
    expect($log->http_status_code)->toBeIn([404, 500, 503]);
});

test('website log history records slow response', function () {
    $log = WebsiteLogHistory::factory()->slow()->create();

    expect($log->speed)->toBeGreaterThan(2000);
});

test('website log history can track ssl expiry', function () {
    $expiryDate = now()->addMonths(2);
    $log = WebsiteLogHistory::factory()->create([
        'ssl_expiry_date' => $expiryDate,
    ]);

    expect($log->ssl_expiry_date->format('Y-m-d H:i'))->toBe($expiryDate->format('Y-m-d H:i'));
});

test('website log history tracks response time', function () {
    $log = WebsiteLogHistory::factory()->create();

    expect($log->speed)->not->toBeNull();
    expect($log->speed)->toBeInt();
});

test('website log history records transport errors', function () {
    $log = WebsiteLogHistory::factory()->transportError('tls')->create([
        'transport_error_message' => 'cURL error 60: SSL certificate problem.',
        'transport_error_code' => 60,
    ]);

    expect($log->http_status_code)->toBe(0)
        ->and($log->transport_error_type)->toBe('tls')
        ->and($log->transport_error_message)->toContain('SSL certificate problem')
        ->and($log->transport_error_code)->toBe(60);
});

test('website log history transport error factory keeps evidence consistent with type', function (
    string $type,
    int $code,
    string $message,
) {
    $log = WebsiteLogHistory::factory()->transportError($type)->create();

    expect($log->transport_error_type)->toBe($type)
        ->and($log->transport_error_code)->toBe($code)
        ->and($log->transport_error_message)->toContain($message);
})->with([
    'dns' => ['dns', 6, 'Could not resolve host'],
    'timeout' => ['timeout', 28, 'Operation timed out'],
    'tls' => ['tls', 60, 'SSL certificate problem'],
    'connection' => ['connection', 7, 'Failed to connect'],
]);
