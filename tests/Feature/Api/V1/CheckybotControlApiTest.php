<?php

use App\Models\ApiKey;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Mimir',
    ]);
    $this->project = Project::factory()->create([
        'created_by' => $this->user->id,
        'package_key' => 'scrappa',
        'name' => 'Scrappa',
        'environment' => 'production',
        'base_url' => 'https://api.scrappa.test',
        'repository' => 'marin-solutions/scrappa',
    ]);
});

test('control api requires a valid api key and exposes me details', function () {
    $this->getJson('/api/v1/control/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Bearer API key is missing');

    $this->withToken('ck_invalid')
        ->getJson('/api/v1/control/me')
        ->assertUnauthorized()
        ->assertJsonPath('message', 'Invalid or expired API key');

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/me')
        ->assertOk()
        ->assertJsonPath('data.authenticated', true)
        ->assertJsonPath('data.api_key.name', 'Mimir')
        ->assertJsonPath('data.user.id', $this->user->id)
        ->assertJsonStructure(['data' => ['server_time']]);
});

test('control api lists projects and package managed checks with compact status', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/health',
        'http_method' => 'GET',
        'request_path' => '/health',
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer secret-token',
        ],
        'current_status' => 'danger',
        'status_summary' => 'API heartbeat failed with HTTP status 500.',
    ]);

    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $monitor->id,
        'summary' => 'API heartbeat failed with HTTP status 500.',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'scrappa')
        ->assertJsonPath('data.0.checks_count', 1)
        ->assertJsonPath('data.0.enabled_checks_count', 1);

    $response = $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'search-health')
        ->assertJsonPath('data.0.status', 'danger')
        ->assertJsonPath('data.0.headers.Accept', 'application/json')
        ->assertJsonPath('data.0.headers.Authorization', '[redacted]');

    expect(json_encode($response->json()))->not->toContain('secret-token');
});

test('control api upserts checks by stable key and redacts encrypted headers', function () {
    $payload = [
        'type' => 'api',
        'name' => 'Google Maps Search',
        'method' => 'GET',
        'url' => '/api/google-maps/search',
        'headers' => [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer package-secret',
            'X-Api-Key' => 'scrappa-secret',
        ],
        'request_body_type' => 'json',
        'request_body' => [
            'email' => 'monitor@example.com',
            'password' => 'body-secret',
        ],
        'expected_status' => 200,
        'timeout_seconds' => 15,
        'schedule' => 'every_5_minutes',
        'assertions' => [
            ['type' => 'json_path_exists', 'path' => '$.data'],
        ],
    ];

    $created = $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/google-maps-search', $payload)
        ->assertCreated()
        ->assertJsonPath('data.created', true)
        ->assertJsonPath('data.check.key', 'google-maps-search')
        ->assertJsonPath('data.check.headers.Accept', 'application/json')
        ->assertJsonPath('data.check.headers.Authorization', '[redacted]')
        ->assertJsonPath('data.check.request_body_type', 'json')
        ->assertJsonPath('data.check.has_request_body', true);

    expect(json_encode($created->json()))->not->toContain('package-secret')
        ->and(json_encode($created->json()))->not->toContain('scrappa-secret')
        ->and(json_encode($created->json()))->not->toContain('body-secret');

    $updated = $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/google-maps-search', array_merge($payload, [
            'name' => 'Google Maps Search API',
            'timeout_seconds' => 20,
        ]))
        ->assertOk()
        ->assertJsonPath('data.created', false)
        ->assertJsonPath('data.check.name', 'Google Maps Search API')
        ->assertJsonPath('data.check.timeout_seconds', 20);

    expect(DB::table('monitor_apis')->where('package_name', 'google-maps-search')->count())->toBe(1)
        ->and(json_encode($updated->json()))->not->toContain('package-secret');

    $rawHeaders = DB::table('monitor_apis')
        ->where('package_name', 'google-maps-search')
        ->value('headers');
    $storedInterval = DB::table('monitor_apis')
        ->where('package_name', 'google-maps-search')
        ->value('package_interval');
    $storedSchedule = DB::table('monitor_apis')
        ->where('package_name', 'google-maps-search')
        ->value('package_schedule');
    $rawRequestBody = DB::table('monitor_apis')
        ->where('package_name', 'google-maps-search')
        ->value('request_body');

    expect($rawHeaders)->toContain('encrypted')
        ->and($rawHeaders)->not->toContain('package-secret')
        ->and($rawHeaders)->not->toContain('scrappa-secret');
    expect($storedInterval)->toBe('5m')
        ->and($storedSchedule)->toBe('every_5_minutes')
        ->and($rawRequestBody)->toContain('encrypted')
        ->and($rawRequestBody)->not->toContain('body-secret');
});

test('control api disables checks without deleting data', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'is_enabled' => true,
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
    ]);

    $this->withToken($this->apiKey->key)
        ->patchJson('/api/v1/control/projects/scrappa/checks/search-health/disable')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.status', 'unknown');

    $this->assertDatabaseHas('monitor_apis', [
        'id' => $monitor->id,
        'is_enabled' => false,
        'deleted_at' => null,
    ]);

    $this->assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $monitor->id,
    ]);
});

test('control api triggers a check run and lists recent failures', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'error']], 500),
    ]);

    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/health',
        'data_path' => 'data.status',
        'is_enabled' => true,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/search-health/runs')
        ->assertOk()
        ->assertJsonPath('data.check.key', 'search-health')
        ->assertJsonPath('data.result.success', false)
        ->assertJsonPath('data.result.status', 'danger');

    $monitor->refresh();

    expect($monitor->current_status)->toBe('danger')
        ->and($monitor->last_heartbeat_at)->not->toBeNull();

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/failures?project=scrappa')
        ->assertOk()
        ->assertJsonPath('data.0.check.key', 'search-health')
        ->assertJsonPath('data.0.status', 'danger');
});

test('control api returns project detail and recent runs', function () {
    $monitor = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'current_status' => 'warning',
        'is_enabled' => true,
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'status' => 'warning',
        'summary' => 'API heartbeat is degraded with HTTP status 404.',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa')
        ->assertOk()
        ->assertJsonPath('data.key', 'scrappa')
        ->assertJsonPath('data.checks_count', 1)
        ->assertJsonPath('data.enabled_checks_count', 1)
        ->assertJsonPath('data.status_counts.warning', 1)
        ->assertJsonPath('data.latest_failure.check.key', 'search-health');

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/runs?project=scrappa')
        ->assertOk()
        ->assertJsonPath('data.0.check.key', 'search-health')
        ->assertJsonPath('data.0.status', 'warning');

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/runs')
        ->assertOk()
        ->assertJsonPath('data.0.check.key', 'search-health');
});

test('control api returns latest result for each listed check', function () {
    $alpha = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'alpha-health',
        'title' => 'Alpha health',
    ]);
    $beta = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'beta-health',
        'title' => 'Beta health',
    ]);

    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $alpha->id,
        'created_at' => now()->subMinutes(2),
    ]);
    MonitorApiResult::factory()->failed()->create([
        'monitor_api_id' => $alpha->id,
        'status' => 'danger',
        'created_at' => now(),
    ]);
    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $beta->id,
        'status' => 'healthy',
        'created_at' => now()->subMinute(),
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'alpha-health')
        ->assertJsonPath('data.0.latest_result.status', 'danger')
        ->assertJsonPath('data.1.key', 'beta-health')
        ->assertJsonPath('data.1.latest_result.status', 'healthy');
});

test('control api scopes projects to the api key owner', function () {
    $otherUser = User::factory()->create();
    $otherApiKey = ApiKey::factory()->create(['user_id' => $otherUser->id]);

    $this->withToken($otherApiKey->key)
        ->getJson('/api/v1/control/projects/scrappa')
        ->assertNotFound();

    $this->withToken($otherApiKey->key)
        ->getJson('/api/v1/control/projects/scrappa/checks')
        ->assertNotFound();
});

test('control api triggers all enabled project checks synchronously', function () {
    Http::fake([
        '*' => Http::response(['data' => ['status' => 'ok']], 200),
    ]);

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'title' => 'Search health',
        'url' => 'https://api.scrappa.test/health',
        'data_path' => 'data.status',
        'is_enabled' => true,
    ]);

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'disabled-health',
        'title' => 'Disabled health',
        'url' => 'https://api.scrappa.test/disabled',
        'is_enabled' => false,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/runs')
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.checks_run', 1)
        ->assertJsonPath('data.results.0.check.key', 'search-health')
        ->assertJsonPath('data.results.0.result.status', 'healthy');
});

test('control api trigger runs respect stored method and expected status', function () {
    Http::fake(function (HttpRequest $request) {
        expect($request->method())->toBe('POST');

        return Http::response([
            'data' => ['status' => 'created'],
        ], 201);
    });

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'create-job',
        'title' => 'Create job',
        'url' => 'https://api.scrappa.test/jobs',
        'http_method' => 'POST',
        'expected_status' => 201,
        'data_path' => 'data.status',
        'is_enabled' => true,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/create-job/runs')
        ->assertOk()
        ->assertJsonPath('data.check.key', 'create-job')
        ->assertJsonPath('data.result.success', true)
        ->assertJsonPath('data.result.http_code', 201)
        ->assertJsonPath('data.result.status', 'healthy');

    Http::assertSentCount(1);
});

test('control api rejects disabled check runs and relative urls without a project base url', function () {
    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'disabled-health',
        'is_enabled' => false,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/control/projects/scrappa/checks/disabled-health/runs')
        ->assertStatus(409)
        ->assertJsonPath('message', 'Check is disabled. Enable or upsert the check before triggering a run.');

    $projectWithoutBaseUrl = Project::factory()->create([
        'created_by' => $this->user->id,
        'package_key' => 'missing-base-url',
        'base_url' => null,
    ]);

    $this->withToken($this->apiKey->key)
        ->putJson("/api/v1/control/projects/{$projectWithoutBaseUrl->package_key}/checks/relative-health", [
            'name' => 'Relative health',
            'url' => '/health',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');
});

test('control api rejects invalid schedules', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/invalid-schedule', [
            'name' => 'Invalid schedule',
            'url' => '/health',
            'schedule' => 'every_friday',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('schedule');
});

test('control api requires body type when request body is provided', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/login-api', [
            'name' => 'Login API',
            'url' => '/login',
            'method' => 'POST',
            'request_body' => ['probe' => true],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body_type');

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/empty-login-api', [
            'name' => 'Empty Login API',
            'url' => '/login',
            'method' => 'POST',
            'request_body' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body_type');
});

test('control api limits request body size', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/login-api', [
            'name' => 'Login API',
            'url' => '/login',
            'method' => 'POST',
            'request_body_type' => 'raw',
            'request_body' => str_repeat('a', 65536),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body');
});

test('control api rejects unstructured json and form request bodies', function () {
    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/login-api', [
            'name' => 'Login API',
            'url' => '/login',
            'method' => 'POST',
            'request_body_type' => 'json',
            'request_body' => 'email=monitor@example.com',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body');

    $this->withToken($this->apiKey->key)
        ->putJson('/api/v1/control/projects/scrappa/checks/token-api', [
            'name' => 'Token API',
            'url' => '/token',
            'method' => 'POST',
            'request_body_type' => 'form',
            'request_body' => 'grant_type=client_credentials',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request_body');
});

test('mcp endpoint lists tools and calls the shared control surface', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'me');

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'ping',
        ])
        ->assertOk()
        ->assertJsonPath('result.ok', true);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'search-health',
                    'name' => 'Search health',
                    'url' => '/health',
                    'headers' => [
                        'Authorization' => 'Bearer mcp-secret',
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.check.key', 'search-health')
        ->assertJsonPath('result.structuredContent.check.headers.Authorization', '[redacted]');

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'search-health',
        'url' => 'https://api.scrappa.test/health',
    ]);
});

test('mcp endpoint rejects invalid schedules with a field validation error', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'search-health',
                    'name' => 'Search health',
                    'url' => '/health',
                    'schedule' => 'every_friday',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.data.errors.schedule.0', 'The schedule format is invalid. Use format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}.');
});

test('mcp endpoint requires body type when request body is provided', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'login-api',
                    'name' => 'Login API',
                    'url' => '/login',
                    'method' => 'POST',
                    'request_body' => ['probe' => true],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.data.errors.request_body_type.0', 'The request body type field is required when request body is present.');

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'empty-login-api',
                    'name' => 'Empty Login API',
                    'url' => '/login',
                    'method' => 'POST',
                    'request_body' => [],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.data.errors.request_body_type.0', 'The request body type field is required when request body is present.');
});

test('mcp endpoint rejects unstructured json request body', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => [
                'name' => 'upsert_check',
                'arguments' => [
                    'project' => 'scrappa',
                    'key' => 'login-api',
                    'name' => 'Login API',
                    'url' => '/login',
                    'method' => 'POST',
                    'request_body_type' => 'json',
                    'request_body' => 'email=monitor@example.com',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('error.data.errors.request_body.0', 'The request_body field must be a JSON object or array for json request bodies.');
});
