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
    ]);

    expect($log->website_id)->toBe($website->id);
    expect($log->ssl_expiry_date)->not->toBeNull();
    expect($log->http_status_code)->toBe(200);
    expect($log->speed)->toBe(350);
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
