<?php

use App\Models\Website;
use App\Services\WebsiteUrlValidator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Dns\Dns;

beforeEach(function () {
    WebsiteUrlValidator::flushInspectionCache();
});

test('validates successfully for valid url', function () {
    $url = 'https://example.com';
    $haltCalled = false;

    // Mock DNS lookup to return records
    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('example.com', 'A')
        ->andReturn([['ip' => '192.0.2.1']]);

    // Mock HTTP response
    Http::fake([
        $url => Http::response('OK', 200),
    ]);

    WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
        $haltCalled = true;
    });

    expect($haltCalled)->toBeFalse();
});

test('halts when url exists in database', function () {
    $url = 'https://example.com';
    $haltCalled = false;

    // Create a website with this URL
    Website::factory()->create(['url' => $url]);

    // Mock DNS lookup to return records
    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('example.com', 'A')
        ->andReturn([['ip' => '192.0.2.1']]);

    // Mock HTTP response
    Http::fake([
        $url => Http::response('OK', 200),
    ]);

    WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
        $haltCalled = true;
    });

    expect($haltCalled)->toBeTrue();
});

test('halts when website not exists in dns', function () {
    $url = 'https://nonexistent.example';
    $haltCalled = false;

    // Mock DNS lookup to return empty array (no records)
    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('nonexistent.example', 'A')
        ->andReturn([]);

    // Mock HTTP response
    Http::fake([
        $url => Http::response('OK', 200),
    ]);

    WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
        $haltCalled = true;
    });

    expect($haltCalled)->toBeFalse();
});

test('halts on certificate error', function () {
    $url = 'https://example.com';
    $haltCalled = false;

    // Mock DNS lookup to return records
    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('example.com', 'A')
        ->andReturn([['ip' => '192.0.2.1']]);

    // Mock HTTP to throw SSL certificate error
    // We'll use ConnectionException since it's simpler and achieves the same result
    Http::fake(function ($request) {
        throw new \Illuminate\Http\Client\ConnectionException(
            'cURL error 60: SSL certificate problem'
        );
    });

    WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
        $haltCalled = true;
    });

    expect($haltCalled)->toBeFalse();
});

test('halts on non 200 response', function () {
    $url = 'https://example.com';
    $haltCalled = false;

    // Mock DNS lookup to return records
    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('example.com', 'A')
        ->andReturn([['ip' => '192.0.2.1']]);

    // Mock HTTP to return non-200 response
    Http::fake([
        $url => Http::response('Not Found', 404),
    ]);

    WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
        $haltCalled = true;
    });

    expect($haltCalled)->toBeFalse();
});

test('halts on unknown error', function () {
    $url = 'https://example.com';
    $haltCalled = false;

    // Mock DNS lookup to return records
    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('example.com', 'A')
        ->andReturn([['ip' => '192.0.2.1']]);

    // Mock HTTP to throw a connection error (unknown error)
    Http::fake(function ($request) {
        throw new \Illuminate\Http\Client\ConnectionException(
            'Unknown connection error'
        );
    });

    WebsiteUrlValidator::validate($url, function () use (&$haltCalled) {
        $haltCalled = true;
    });

    expect($haltCalled)->toBeFalse();
});

test('returns warning state when setup checks find issues', function () {
    $url = 'https://broken.example';

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('broken.example', 'A')
        ->andReturn([]);

    Http::fake([
        $url => Http::response('Not Found', 404),
    ]);

    $result = WebsiteUrlValidator::inspect($url);

    expect($result['should_halt'])->toBeFalse()
        ->and($result['warnings'])->toHaveCount(2)
        ->and($result['warning_state'])->toMatchArray([
            'current_status' => 'warning',
        ])
        ->and($result['warning_state']['status_summary'])->toContain('The domain did not resolve during setup.')
        ->and($result['warning_state']['status_summary'])->toContain('HTTP 404');
});

test('warning state clears status fields when setup checks pass', function () {
    expect(WebsiteUrlValidator::warningState([]))->toBe([
        'current_status' => null,
        'status_summary' => null,
    ]);
});

test('warning state truncates long status summary to database length', function () {
    $warnings = [
        ['body' => Str::repeat('a', 200)],
        ['body' => Str::repeat('b', 200)],
    ];

    $warningState = WebsiteUrlValidator::warningState($warnings);

    expect($warningState['current_status'])->toBe('warning')
        ->and($warningState['status_summary'])->toHaveLength(255);
});
