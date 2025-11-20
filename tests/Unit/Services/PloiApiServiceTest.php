<?php

namespace Tests\Unit\Services;

use App\Services\PloiApiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PloiApiServiceTest extends TestCase
{
    public function test_verify_key_returns_success_on_valid_key(): void
    {
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

        $this->assertTrue($result['is_verified']);
        $this->assertEquals('The API key verification was successful.', $result['error_message']);
    }

    public function test_verify_key_returns_failure_on_invalid_key(): void
    {
        Http::fake([
            'ploi.io/api/user' => Http::response([
                'error' => 'Unauthorized',
            ], 401),
        ]);

        $result = PloiApiService::verifyKey('invalid-key');

        $this->assertFalse($result['is_verified']);
        $this->assertStringContainsString('API verification failed', $result['error_message']);
    }

    public function test_verify_key_uses_correct_authorization_header(): void
    {
        Http::fake();

        PloiApiService::verifyKey('my-secret-token');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer my-secret-token');
        });
    }

    public function test_verify_key_sends_correct_headers(): void
    {
        Http::fake();

        PloiApiService::verifyKey('test-key');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Accept', 'application/json') &&
                   $request->hasHeader('Content-Type', 'application/json') &&
                   $request->hasHeader('Authorization', 'Bearer test-key');
        });
    }

    public function test_verify_key_handles_network_exception(): void
    {
        Http::fake([
            'ploi.io/api/user' => function () {
                throw new \Exception('Network error');
            },
        ]);

        $result = PloiApiService::verifyKey('test-key');

        $this->assertFalse($result['is_verified']);
        $this->assertStringContainsString('Network error', $result['error_message']);
    }

    public function test_verify_key_handles_various_http_error_codes(): void
    {
        $errorCodes = [400, 403, 404, 500, 503];

        foreach ($errorCodes as $code) {
            Http::fake([
                'ploi.io/api/user' => Http::response(['error' => 'Error'], $code),
            ]);

            $result = PloiApiService::verifyKey('test-key');

            $this->assertFalse($result['is_verified'], "Failed for status code {$code}");
            $this->assertStringContainsString('API verification failed', $result['error_message']);
        }
    }

    public function test_verify_key_calls_correct_endpoint(): void
    {
        Http::fake();

        PloiApiService::verifyKey('test-key');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://ploi.io/api/user';
        });
    }
}
