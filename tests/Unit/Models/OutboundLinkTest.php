<?php

use App\Models\OutboundLink;

test('outbound link has correct table name', function () {
    $link = new OutboundLink;

    expect($link->getTable())->toBe('outbound_link');
});

test('outbound link has fillable attributes', function () {
    $link = OutboundLink::create([
        'website_id' => 1,
        'found_on' => 'https://example.com/page',
        'outgoing_url' => 'https://external.com',
        'http_status_code' => 200,
        'last_checked_at' => now(),
    ]);

    expect($link->website_id)->toBe(1);
    expect($link->found_on)->toBe('https://example.com/page');
    expect($link->outgoing_url)->toBe('https://external.com');
    expect($link->http_status_code)->toBe(200);
    expect($link->last_checked_at)->not->toBeNull();
});

test('outbound link tracks found and outgoing urls', function () {
    $link = OutboundLink::create([
        'website_id' => 1,
        'found_on' => 'https://mysite.com/blog/post',
        'outgoing_url' => 'https://partner-site.com',
        'http_status_code' => 200,
        'last_checked_at' => now(),
    ]);

    expect($link->found_on)->toContain('mysite.com');
    expect($link->outgoing_url)->toContain('partner-site.com');
});

test('outbound link records http status code', function () {
    $successLink = OutboundLink::create([
        'website_id' => 1,
        'found_url' => 'https://example.com',
        'outgoing_url' => 'https://external.com',
        'http_status_code' => 200,
        'last_checked_at' => now(),
    ]);

    $errorLink = OutboundLink::create([
        'website_id' => 1,
        'found_url' => 'https://example.com',
        'outgoing_url' => 'https://broken.com',
        'http_status_code' => 404,
        'last_checked_at' => now(),
    ]);

    expect($successLink->http_status_code)->toBe(200);
    expect($errorLink->http_status_code)->toBe(404);
});

test('outbound link tracks last checked timestamp', function () {
    $checkedTime = now()->subHours(2);
    $link = OutboundLink::create([
        'website_id' => 1,
        'found_url' => 'https://example.com',
        'outgoing_url' => 'https://external.com',
        'http_status_code' => 200,
        'last_checked_at' => $checkedTime,
    ]);

    expect($link->last_checked_at->format('Y-m-d H:i'))->toBe($checkedTime->format('Y-m-d H:i'));
});
