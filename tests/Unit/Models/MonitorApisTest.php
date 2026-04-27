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

test('monitor api stores empty headers as null', function () {
    $monitor = MonitorApis::factory()->create([
        'headers' => [],
    ]);

    expect($monitor->getRawOriginal('headers'))->toBeNull()
        ->and($monitor->headers)->toBe([]);
});

test('monitor api encrypts request body at rest', function () {
    $monitor = MonitorApis::factory()->create([
        'request_body_type' => 'json',
        'request_body' => '{"password":"secret"}',
    ]);

    $rawBody = $monitor->getRawOriginal('request_body');

    expect($rawBody)->toContain('encrypted')
        ->and($rawBody)->not->toContain('secret')
        ->and($monitor->request_body)->toBe('{"password":"secret"}');
});

test('monitor api preserves encrypted empty request bodies', function () {
    $monitor = MonitorApis::factory()->create([
        'request_body_type' => 'json',
    ]);

    $monitor->request_body = [];
    $monitor->save();

    $rawBody = $monitor->getRawOriginal('request_body');

    expect($rawBody)->not->toBeNull()
        ->and((string) $rawBody)->toContain('encrypted')
        ->and($monitor->request_body)->toBe('[]');
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

test('monitor api returns null request body when encrypted payload cannot be decrypted', function () {
    $monitor = MonitorApis::factory()->create([
        'request_body_type' => 'json',
        'request_body' => '{"password":"secret"}',
    ]);

    DB::table('monitor_apis')
        ->where('id', $monitor->id)
        ->update([
            'request_body' => json_encode(['encrypted' => 'corrupted-payload']),
        ]);

    expect($monitor->fresh()->request_body)->toBeNull();
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

test('test api returns response time in milliseconds on success', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    $result = MonitorApis::testApi([
        'url' => 'https://api.example.test/timing',
        'method' => 'GET',
        'expected_status' => 200,
    ]);

    expect($result)->toHaveKey('response_time_ms');
    expect($result['response_time_ms'])->toBeInt();
    expect($result['response_time_ms'])->toBeGreaterThanOrEqual(0);
});

test('test api returns response time in milliseconds when the request throws a connection exception', function () {
    Http::fake(function (): never {
        throw new \Illuminate\Http\Client\ConnectionException('timeout');
    });

    $result = MonitorApis::testApi([
        'url' => 'https://api.example.test/timeout',
        'method' => 'GET',
        'expected_status' => 200,
    ]);

    expect($result)->toHaveKey('response_time_ms')
        ->and($result['response_time_ms'])->toBeInt()
        ->and($result['response_time_ms'])->toBeGreaterThanOrEqual(0)
        ->and($result['code'])->toBe(0)
        ->and($result['error'])->toStartWith('Connection timeout:');
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
        ->and(collect($result['assertions'])->contains(
            fn (array $assertion): bool => ($assertion['path'] ?? null) === '_http_status'
                && ($assertion['type'] ?? null) === 'status_code'
                && ($assertion['passed'] ?? null) === false
                && ($assertion['message'] ?? null) === 'Expected HTTP status 200, got 403.'
                && ($assertion['actual'] ?? null) === 403
                && ($assertion['expected'] ?? null) === 200
        ))->toBeTrue();
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

test('test api sends configured json request bodies', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    $result = MonitorApis::testApi([
        'url' => 'https://api.example.test/login',
        'http_method' => 'POST',
        'request_body_type' => 'json',
        'request_body' => '{"email":"monitor@example.com","password":"secret"}',
        'expected_status' => 200,
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.example.test/login'
        && $request->data() === [
            'email' => 'monitor@example.com',
            'password' => 'secret',
        ]);

    expect($result['code'])->toBe(200);
});

test('test api preserves empty json object request bodies', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    MonitorApis::testApi([
        'url' => 'https://api.example.test/object',
        'http_method' => 'POST',
        'request_body_type' => 'json',
        'request_body' => '{}',
        'expected_status' => 200,
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.example.test/object'
        && $request->body() === '{}');
});

test('test api preserves empty json array request bodies', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    MonitorApis::testApi([
        'url' => 'https://api.example.test/array',
        'http_method' => 'POST',
        'request_body_type' => 'json',
        'request_body' => '[]',
        'expected_status' => 200,
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.example.test/array'
        && $request->body() === '[]');
});

test('test api sends configured form request bodies', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    MonitorApis::testApi([
        'url' => 'https://api.example.test/token',
        'method' => 'POST',
        'request_body_type' => 'form',
        'request_body' => '{"grant_type":"client_credentials","scope":"health"}',
        'expected_status' => 200,
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.example.test/token'
        && $request->data() === [
            'grant_type' => 'client_credentials',
            'scope' => 'health',
        ]);
});

test('test api sends url encoded form request bodies', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    MonitorApis::testApi([
        'url' => 'https://api.example.test/token',
        'method' => 'POST',
        'request_body_type' => 'form',
        'request_body' => 'grant_type=client_credentials&scope=health',
        'expected_status' => 200,
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request->url() === 'https://api.example.test/token'
        && $request->data() === [
            'grant_type' => 'client_credentials',
            'scope' => 'health',
        ]);
});

test('test api sends configured raw request bodies', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response('', 204),
    ]);

    MonitorApis::testApi([
        'url' => 'https://api.example.test/search',
        'method' => 'DELETE',
        'request_body_type' => 'raw',
        'request_body' => 'ids=1,2,3',
        'expected_status' => 204,
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://api.example.test/search'
        && $request->body() === 'ids=1,2,3');
});

test('test api loads stored request body when only monitor id is provided', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/stored',
        'http_method' => 'PATCH',
        'request_body_type' => 'json',
        'request_body' => '{"status":"active"}',
    ]);

    MonitorApis::testApi([
        'id' => $monitor->id,
        'url' => $monitor->url,
        'method' => $monitor->http_method,
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && $request->data() === ['status' => 'active']);
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
        ->and(collect($result['assertions'])->contains(
            fn (array $assertion): bool => ($assertion['path'] ?? null) === 'data.status'
                && ($assertion['passed'] ?? null) === false
                && ($assertion['message'] ?? null) === 'Value does not exist at path'
                && ($assertion['actual'] ?? null) === 'missing'
                && ($assertion['expected'] ?? null) === 'exists'
        ))->toBeTrue();
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
        ->and(collect($result['assertions'])->contains(
            fn (array $assertion): bool => ($assertion['path'] ?? null) === '_response_body'
                && ($assertion['type'] ?? null) === 'json_valid'
                && ($assertion['passed'] ?? null) === false
                && ($assertion['message'] ?? null) === $result['error']
                && ($assertion['expected'] ?? null) === 'valid JSON'
                && ($assertion['actual'] ?? null) === 'Syntax error'
        ))->toBeTrue();
});

test('test api still evaluates assertions when json body is literal null', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response('null', 404),
    ]);

    $result = MonitorApis::testApi([
        'url' => 'https://api.example.test/null-body',
        'method' => 'GET',
        'expected_status' => 404,
        'data_path' => 'data.status',
    ]);

    expect($result['code'])->toBe(404)
        ->and($result['error'])->toBeNull()
        ->and(collect($result['assertions'])->contains(
            fn (array $assertion): bool => ($assertion['path'] ?? null) === 'data.status'
                && ($assertion['passed'] ?? null) === false
                && ($assertion['message'] ?? null) === 'Value does not exist at path'
                && ($assertion['actual'] ?? null) === 'missing'
                && ($assertion['expected'] ?? null) === 'exists'
        ))->toBeTrue();
});

test('preview assertion uses latest saved response body when available', function () {
    Http::fake();

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'active',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'http_code' => 200,
        'response_time_ms' => 123,
        'response_body' => ['data' => ['status' => 'pending']],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertNothingSent();

    expect($preview['source'])->toBe('saved_response')
        ->and($preview['source_label'])->toBe('Latest saved response')
        ->and($preview['http_code'])->toBe(200)
        ->and($preview['response_time_ms'])->toBe(123)
        ->and($preview['passed'])->toBeFalse()
        ->and($preview['actual'])->toBe('pending')
        ->and($preview['expected'])->toBe('= active');
});

test('preview assertion runs fresh test when no saved response body exists', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['data' => ['status' => 'active']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
        'http_method' => 'POST',
        'expected_status' => 200,
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'active',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => null,
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://api.example.test/orders');

    expect($preview['source'])->toBe('fresh_test')
        ->and($preview['source_label'])->toBe('Fresh test response')
        ->and($preview['http_code'])->toBe(200)
        ->and($preview['passed'])->toBeTrue()
        ->and($preview['actual'])->toBe('active')
        ->and($preview['expected'])->toBe('= active');
});

test('preview assertion runs fresh test when monitor has no prior result', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['data' => ['status' => 'active']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
        'expected_status' => 200,
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'active',
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.example.test/orders');

    expect($preview['source'])->toBe('fresh_test')
        ->and($preview['passed'])->toBeTrue()
        ->and($preview['actual'])->toBe('active');
});

test('preview assertion falls back to fresh test when latest saved body only contains error metadata', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['data' => ['status' => 'active']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
        'expected_status' => 200,
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'active',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => [
            MonitorApiResult::ERROR_METADATA_KEY => 'Connection timeout: cURL error 28',
        ],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.example.test/orders');

    expect($preview['source'])->toBe('fresh_test')
        ->and($preview['passed'])->toBeTrue()
        ->and($preview['actual'])->toBe('active');
});

test('preview assertion uses legitimate saved error payloads instead of forcing a fresh test', function () {
    Http::fake();

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'error',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'invalid_token',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => [
            'error' => 'invalid_token',
        ],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertNothingSent();

    expect($preview['source'])->toBe('saved_response')
        ->and($preview['passed'])->toBeTrue()
        ->and($preview['actual'])->toBe('invalid_token');
});

test('preview assertion uses non-string saved error payloads instead of treating them as metadata', function () {
    Http::fake();

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'error.code',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'invalid_token',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => [
            'error' => [
                'code' => 'invalid_token',
            ],
        ],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertNothingSent();

    expect($preview['source'])->toBe('saved_response')
        ->and($preview['passed'])->toBeTrue()
        ->and($preview['actual'])->toBe('invalid_token');
});

test('preview assertion falls back to fresh test for legacy error-only metadata payloads', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response(['data' => ['status' => 'active']], 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
        'expected_status' => 200,
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'active',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => [
            'error' => 'Connection timeout: cURL error 28',
        ],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertSent(fn ($request) => $request->url() === 'https://api.example.test/orders');

    expect($preview['source'])->toBe('fresh_test')
        ->and($preview['passed'])->toBeTrue()
        ->and($preview['actual'])->toBe('active');
});

test('preview assertion fails when fresh test has a transport error even if assertion would pass against null', function () {
    Http::fake(function (): never {
        throw new \Illuminate\Http\Client\ConnectionException('timeout');
    });

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'not_exists',
    ]);

    $preview = $monitor->previewAssertion($assertion);

    expect($preview['source'])->toBe('fresh_test')
        ->and($preview['passed'])->toBeFalse()
        ->and($preview['message'])->toStartWith('Connection timeout:')
        ->and($preview['actual'])->toStartWith('Connection timeout:')
        ->and($preview['expected'])->toBe('response body without transport or JSON errors');
});

test('preview assertion fails when fresh test has invalid json even if assertion would pass against null', function () {
    Http::fake([
        'https://api.example.test/*' => Http::response('not-json', 200),
    ]);

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'not_exists',
    ]);

    $preview = $monitor->previewAssertion($assertion);

    expect($preview['source'])->toBe('fresh_test')
        ->and($preview['passed'])->toBeFalse()
        ->and($preview['message'])->toBe('Invalid JSON response: Syntax error')
        ->and($preview['actual'])->toBe('Invalid JSON response: Syntax error')
        ->and($preview['expected'])->toBe('response body without transport or JSON errors');
});

test('preview assertion parses saved raw body wrapper', function () {
    Http::fake();

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'active',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => [
            MonitorApiResult::RAW_BODY_KEY => '{"data":{"status":"pending"}}',
        ],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertNothingSent();

    expect($preview['source'])->toBe('saved_response')
        ->and($preview['passed'])->toBeFalse()
        ->and($preview['actual'])->toBe('pending')
        ->and($preview['expected'])->toBe('= active');
});

test('preview assertion marks saved raw body wrapper invalid when it is malformed json', function () {
    Http::fake();

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'exists',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => [
            MonitorApiResult::RAW_BODY_KEY => 'not-json{{{',
            'error' => 'Invalid JSON response: Syntax error',
        ],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertNothingSent();

    expect($preview['source'])->toBe('saved_response')
        ->and($preview['passed'])->toBeFalse()
        ->and($preview['message'])->toBe('Saved response body is not valid JSON: Syntax error')
        ->and($preview['actual'])->toBe('Syntax error')
        ->and($preview['expected'])->toBe('valid JSON');
});

test('preview assertion treats raw_body as user payload data when response has other fields', function () {
    Http::fake();

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'active',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => [
            'raw_body' => 'this is user data, not an internal wrapper',
            'status' => 'active',
        ],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertNothingSent();

    expect($preview['source'])->toBe('saved_response')
        ->and($preview['passed'])->toBeTrue()
        ->and($preview['actual'])->toBe('active');
});

test('preview assertion treats single raw_body key as user payload data', function () {
    Http::fake();

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'raw_body',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'this is user data, not an internal wrapper',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => [
            'raw_body' => 'this is user data, not an internal wrapper',
        ],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertNothingSent();

    expect($preview['source'])->toBe('saved_response')
        ->and($preview['passed'])->toBeTrue()
        ->and($preview['actual'])->toBe('this is user data, not an internal wrapper');
});

test('preview assertion parses legacy raw body wrapper when error metadata identifies it', function () {
    Http::fake();

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'data.status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'active',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => [
            'raw_body' => '{"data":{"status":"pending"}}',
            'error' => 'Invalid JSON response: Syntax error',
        ],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertNothingSent();

    expect($preview['source'])->toBe('saved_response')
        ->and($preview['passed'])->toBeFalse()
        ->and($preview['actual'])->toBe('pending')
        ->and($preview['expected'])->toBe('= active');
});

test('preview assertion treats internal raw body sentinel as user payload data when response has other fields', function () {
    Http::fake();

    $monitor = MonitorApis::factory()->create([
        'url' => 'https://api.example.test/orders',
    ]);

    $assertion = MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $monitor->id,
        'data_path' => 'status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'active',
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'response_body' => [
            MonitorApiResult::RAW_BODY_KEY => 'this is user data, not an internal wrapper',
            'status' => 'active',
        ],
    ]);

    $preview = $monitor->previewAssertion($assertion);

    Http::assertNothingSent();

    expect($preview['source'])->toBe('saved_response')
        ->and($preview['passed'])->toBeTrue()
        ->and($preview['actual'])->toBe('active');
});
