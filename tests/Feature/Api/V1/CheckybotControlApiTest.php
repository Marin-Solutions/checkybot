<?php

use App\Models\ApiKey;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;
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
        ->assertJsonPath('data.user.id', $this->user->id);
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
        'headers' => ['Authorization' => 'Bearer secret-token'],
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
            'Authorization' => 'Bearer package-secret',
            'X-Api-Key' => 'scrappa-secret',
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
        ->assertJsonPath('data.check.headers.Authorization', '[redacted]');

    expect(json_encode($created->json()))->not->toContain('package-secret')
        ->and(json_encode($created->json()))->not->toContain('scrappa-secret');

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

    expect($rawHeaders)->toContain('encrypted')
        ->and($rawHeaders)->not->toContain('package-secret')
        ->and($rawHeaders)->not->toContain('scrappa-secret');
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
        ->assertAccepted()
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

test('mcp endpoint lists tools and calls the shared control surface', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'checkybot_me');

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'checkybot_upsert_check',
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
