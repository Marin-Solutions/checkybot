<?php

use App\Services\PloiApiService;
use Illuminate\Support\Facades\Http;

test('verify key returns success on valid key', function () {
    Http::fake([
        'ploi.io/api/user' => Http::response([
            'data' => [
                'id' => 1,
                'name' => 'Test User',
                'email' => 'test@example.com',
            ],
        ], 200),
    ]);

    $result = PloiApiService::verifyKey('valid-key');

    expect($result['is_verified'])->toBeTrue();
    expect($result['error_message'])->toBe('The API key verification was successful.');
});

test('verify key returns failure on invalid key', function () {
    Http::fake([
        'ploi.io/api/user' => Http::response([
            'error' => 'Unauthorized',
        ], 401),
    ]);

    $result = PloiApiService::verifyKey('invalid-key');

    expect($result['is_verified'])->toBeFalse();
    expect($result['error_message'])->toContain('API verification failed');
});

test('verify key uses correct authorization header', function () {
    Http::fake();

    PloiApiService::verifyKey('my-secret-token');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer my-secret-token');
    });
});

test('verify key sends correct headers', function () {
    Http::fake();

    PloiApiService::verifyKey('test-key');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Accept', 'application/json') &&
               $request->hasHeader('Content-Type', 'application/json') &&
               $request->hasHeader('Authorization', 'Bearer test-key');
    });
});

test('verify key handles network exception', function () {
    Http::fake([
        'ploi.io/api/user' => function () {
            throw new \Exception('Network error');
        },
    ]);

    $result = PloiApiService::verifyKey('test-key');

    expect($result['is_verified'])->toBeFalse();
    expect($result['error_message'])->toContain('Network error');
});

test('verify key handles various http error codes', function () {
    $errorCodes = [400, 403, 404, 500, 503];

    foreach ($errorCodes as $code) {
        Http::fake([
            'ploi.io/api/user' => Http::response(['error' => 'Error'], $code),
        ]);

        $result = PloiApiService::verifyKey('test-key');

        expect($result['is_verified'])->toBeFalse("Failed for status code {$code}");
        expect($result['error_message'])->toContain('API verification failed');
    }
});

test('verify key calls correct endpoint', function () {
    Http::fake();

    PloiApiService::verifyKey('test-key');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://ploi.io/api/user';
    });
});
