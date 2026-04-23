<?php

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('monitor api belongs to user', function () {
    $user = User::factory()->create();
    $monitor = MonitorApis::factory()->create(['created_by' => $user->id]);

    expect($monitor->user)->toBeInstanceOf(User::class);
    expect($monitor->user->id)->toBe($user->id);
});

test('monitor api has many assertions', function () {
    $monitor = MonitorApis::factory()->create();
    MonitorApiAssertion::factory()->count(3)->create(['monitor_api_id' => $monitor->id]);

    expect($monitor->assertions)->toHaveCount(3);
    expect($monitor->assertions->first())->toBeInstanceOf(MonitorApiAssertion::class);
});

test('monitor api assertions are ordered by sort order', function () {
    $monitor = MonitorApis::factory()->create();

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'sort_order' => 3,
    ]);
    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'sort_order' => 1,
    ]);
    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'sort_order' => 2,
    ]);

    $sortOrders = $monitor->fresh()->assertions->pluck('sort_order')->toArray();

    expect($sortOrders)->toBe([1, 2, 3]);
});

test('monitor api has many results', function () {
    $monitor = MonitorApis::factory()->create();
    MonitorApiResult::factory()->count(10)->create(['monitor_api_id' => $monitor->id]);

    expect($monitor->results)->toHaveCount(10);
    expect($monitor->results->first())->toBeInstanceOf(MonitorApiResult::class);
});

test('monitor api requires title', function () {
    MonitorApis::factory()->create(['title' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('monitor api requires url', function () {
    MonitorApis::factory()->create(['url' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('monitor api stores headers as json', function () {
    $headers = [
        'Authorization' => 'Bearer token123',
        'Accept' => 'application/json',
    ];

    $monitor = MonitorApis::factory()->create([
        'headers' => json_encode($headers),
    ]);

    expect($monitor->headers)->toBe($headers);
});

test('monitor api encrypts headers at rest', function () {
    $monitor = MonitorApis::factory()->create([
        'headers' => [
            'Authorization' => 'Bearer token123',
            'Accept' => 'application/json',
        ],
    ]);

    $rawHeaders = $monitor->getRawOriginal('headers');

    expect($rawHeaders)->toContain('encrypted')
        ->and($rawHeaders)->not->toContain('token123')
        ->and($monitor->headers['Authorization'])->toBe('Bearer token123');
});

test('monitor api returns empty headers when encrypted payload cannot be decrypted', function () {
    $monitor = MonitorApis::factory()->create([
        'headers' => [
            'Authorization' => 'Bearer token123',
        ],
    ]);

    DB::table('monitor_apis')
        ->where('id', $monitor->id)
        ->update([
            'headers' => json_encode(['encrypted' => 'corrupted-payload']),
        ]);

    expect($monitor->fresh()->headers)->toBe([]);
});

test('monitor api has data path for response extraction', function () {
    $monitor = MonitorApis::factory()->create([
        'data_path' => 'data.results.items',
    ]);

    expect($monitor->data_path)->toBe('data.results.items');
});

test('monitor api redacts sensitive query parameters in log-safe urls', function () {
    $method = new ReflectionMethod(MonitorApis::class, 'sanitizeUrlForLogs');
    $method->setAccessible(true);

    $sanitized = $method->invoke(null, 'https://api.example.test/health?token=secret-token&plain=value');

    expect($sanitized)
        ->toBe('https://api.example.test/health?token=%5Bredacted%5D&plain=value')
        ->not->toContain('secret-token');
});

test('test api preserves final http error status after retries', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['message' => 'forbidden'], 403),
    ]);

    $result = MonitorApis::testApi([
        'url' => 'https://api.example.test/blocked',
        'method' => 'GET',
        'expected_status' => 200,
    ]);

    expect($result['code'])->toBe(403)
        ->and($result['assertions'])->toContain([
            'path' => '_http_status',
            'type' => 'status_code',
            'passed' => false,
            'message' => 'Expected HTTP status 200, got 403.',
        ]);
});

test('test api treats null json values as existing data paths', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['data' => ['optional' => null]], 200),
    ]);

    $result = MonitorApis::testApi([
        'url' => 'https://api.example.test/nullable',
        'method' => 'GET',
        'data_path' => 'data.optional',
    ]);

    expect($result['code'])->toBe(200)
        ->and($result['assertions'][0]['passed'])->toBeTrue()
        ->and($result['assertions'][0]['message'])->toBe('Value exists at path');
});

test('test api accepts http_method input from filament form flows', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response('', 204),
    ]);

    $result = MonitorApis::testApi([
        'url' => 'https://api.example.test/health',
        'http_method' => 'POST',
        'expected_status' => 204,
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://api.example.test/health');

    expect($result['code'])->toBe(204)
        ->and($result['assertions'])->toBe([]);
});

test('test api still evaluates assertions for expected 404 json responses', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['data' => []], 404),
    ]);

    $result = MonitorApis::testApi([
        'url' => 'https://api.example.test/missing',
        'method' => 'GET',
        'expected_status' => 404,
        'data_path' => 'data.status',
    ]);

    expect($result['code'])->toBe(404)
        ->and($result['assertions'])->toContain([
            'path' => 'data.status',
            'passed' => false,
            'message' => 'Value does not exist at path',
        ]);
});

test('test api flags invalid json for expected 404 responses that require assertions', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response('not-json', 404),
    ]);

    $result = MonitorApis::testApi([
        'url' => 'https://api.example.test/malformed-missing',
        'method' => 'GET',
        'expected_status' => 404,
        'data_path' => 'data.status',
    ]);

    expect($result['code'])->toBe(404)
        ->and($result['error'])->toStartWith('Invalid JSON response:')
        ->and($result['assertions'])->toContain([
            'path' => '_response_body',
            'type' => 'json_valid',
            'passed' => false,
            'message' => $result['error'],
        ]);
});
