<?php

use App\Models\ApiKey;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);
});

function packageSyncPayload(array $overrides = []): array
{
    $payload = [
        'project' => [
            'key' => 'scrappa',
            'name' => 'Scrappa',
            'environment' => 'production',
            'base_url' => 'https://api.scrappa.co',
            'repository' => 'userlip/scrappa',
        ],
        'defaults' => [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer default-secret',
            ],
            'timeout_seconds' => 10,
        ],
        'checks' => [
            [
                'key' => 'google-maps-search',
                'type' => 'api',
                'name' => 'Google Maps search API',
                'method' => 'GET',
                'url' => '/api/google-maps/search',
                'headers' => [
                    'X-Api-Key' => 'secret-package-token',
                ],
                'request_body_type' => 'json',
                'request_body' => [
                    'email' => 'monitor@example.com',
                    'password' => 'secret',
                ],
                'expected_status' => 200,
                'timeout_seconds' => 15,
                'assertions' => [
                    ['type' => 'json_path_exists', 'path' => '$.data'],
                ],
                'schedule' => 'every_5_minutes',
                'enabled' => true,
            ],
        ],
    ];

    return array_replace_recursive($payload, $overrides);
}

test('package sync requires a valid api key', function () {
    $this->postJson('/api/v1/package/sync', packageSyncPayload())
        ->assertUnauthorized();

    $this->withToken('ck_invalid')
        ->postJson('/api/v1/package/sync', packageSyncPayload())
        ->assertUnauthorized();
});

test('package sync creates a project and api check definitions', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload());

    $response->assertCreated()
        ->assertJsonPath('data.project.key', 'scrappa')
        ->assertJsonPath('data.summary.created', 1)
        ->assertJsonPath('data.summary.updated', 0)
        ->assertJsonPath('data.summary.disabled_missing', 0);

    $projectId = $response->json('data.project.id');

    $this->assertDatabaseHas('projects', [
        'id' => $projectId,
        'created_by' => $this->user->id,
        'package_key' => 'scrappa',
        'name' => 'Scrappa',
        'environment' => 'production',
        'base_url' => 'https://api.scrappa.co',
        'repository' => 'userlip/scrappa',
    ]);

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $projectId,
        'title' => 'Google Maps search API',
        'url' => 'https://api.scrappa.co/api/google-maps/search',
        'http_method' => 'GET',
        'request_path' => '/api/google-maps/search',
        'expected_status' => 200,
        'timeout_seconds' => 15,
        'request_body_type' => 'json',
        'package_schedule' => 'every_5_minutes',
        'package_interval' => '5m',
        'package_name' => 'google-maps-search',
        'source' => 'package',
        'is_enabled' => true,
    ]);

    $monitor = MonitorApis::query()->where('package_name', 'google-maps-search')->sole();

    expect($monitor->headers)->toBe([
        'Accept' => 'application/json',
        'Authorization' => 'Bearer default-secret',
        'X-Api-Key' => 'secret-package-token',
    ])->and($monitor->assertions)->toHaveCount(1)
        ->and($monitor->request_body_type)->toBe('json')
        ->and($monitor->request_body)->toBe('{"email":"monitor@example.com","password":"secret"}')
        ->and($monitor->assertions->first()->assertion_type)->toBe('exists')
        ->and($monitor->assertions->first()->data_path)->toBe('data');
});

test('package sync is idempotent and updates by stable keys', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload())
        ->assertCreated();

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'project' => [
                'name' => 'Scrappa API',
            ],
            'checks' => [
                [
                    'key' => 'google-maps-search',
                    'name' => 'Google Maps Search',
                    'url' => '/api/google-maps/v2/search',
                    'timeout_seconds' => 20,
                ],
            ],
        ]));

    $response->assertOk()
        ->assertJsonPath('data.summary.created', 0)
        ->assertJsonPath('data.summary.updated', 1);

    expect(DB::table('projects')->where('package_key', 'scrappa')->count())->toBe(1)
        ->and(DB::table('monitor_apis')->where('package_name', 'google-maps-search')->count())->toBe(1);

    $this->assertDatabaseHas('monitor_apis', [
        'package_name' => 'google-maps-search',
        'title' => 'Google Maps Search',
        'url' => 'https://api.scrappa.co/api/google-maps/v2/search',
        'timeout_seconds' => 20,
    ]);
});

test('package sync encrypts header values and does not return them', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload());

    $response->assertCreated();

    $rawHeaders = DB::table('monitor_apis')
        ->where('package_name', 'google-maps-search')
        ->value('headers');
    $rawRequestBody = DB::table('monitor_apis')
        ->where('package_name', 'google-maps-search')
        ->value('request_body');
    $rawProjectDefaults = DB::table('projects')
        ->where('package_key', 'scrappa')
        ->value('sync_defaults');

    expect(json_encode($response->json()))->not->toContain('secret-package-token')
        ->and(json_encode($response->json()))->not->toContain('default-secret')
        ->and($rawHeaders)->toContain('encrypted')
        ->and($rawHeaders)->not->toContain('secret-package-token')
        ->and($rawHeaders)->not->toContain('default-secret')
        ->and($rawRequestBody)->toContain('encrypted')
        ->and($rawRequestBody)->not->toContain('secret')
        ->and($rawProjectDefaults)->not->toContain('default-secret');
});

test('package sync disables missing package api checks without deleting them', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload())
        ->assertCreated();

    $payload = packageSyncPayload();
    $payload['checks'] = [];

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', $payload);

    $response->assertOk()
        ->assertJsonPath('data.summary.disabled_missing', 1);

    $this->assertDatabaseHas('monitor_apis', [
        'package_name' => 'google-maps-search',
        'is_enabled' => false,
        'deleted_at' => null,
    ]);
});

test('package sync returns validation errors for malformed payloads', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'project' => [
                'key' => '',
                'base_url' => 'not-a-url',
            ],
            'checks' => [
                [
                    'key' => 'bad check key',
                    'type' => 'api',
                    'expected_status' => 99,
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'project.key',
            'project.base_url',
            'checks.0.key',
            'checks.0.expected_status',
        ]);
});

test('package sync rejects invalid schedules', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'google-maps-search',
                    'type' => 'api',
                    'name' => 'Google Maps search API',
                    'method' => 'GET',
                    'url' => '/api/google-maps/search',
                    'schedule' => 'every_friday',
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.schedule',
        ]);
});

test('package sync requires body type when a request body is provided', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'google-maps-search',
                    'type' => 'api',
                    'name' => 'Google Maps search API',
                    'method' => 'POST',
                    'url' => '/api/google-maps/search',
                    'request_body_type' => null,
                    'request_body' => ['probe' => true],
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.request_body_type',
        ]);
});

test('package sync limits request body size', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'google-maps-search',
                    'type' => 'api',
                    'name' => 'Google Maps search API',
                    'method' => 'POST',
                    'url' => '/api/google-maps/search',
                    'request_body_type' => 'raw',
                    'request_body' => str_repeat('a', 65536),
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.request_body',
        ]);
});

test('package sync rejects unstructured json and form request bodies', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'json-login',
                    'type' => 'api',
                    'name' => 'JSON Login API',
                    'method' => 'POST',
                    'url' => '/api/login',
                    'request_body_type' => 'json',
                    'request_body' => 'email=monitor@example.com',
                ],
                [
                    'key' => 'form-token',
                    'type' => 'api',
                    'name' => 'Form Token API',
                    'method' => 'POST',
                    'url' => '/api/token',
                    'request_body_type' => 'form',
                    'request_body' => 'grant_type=client_credentials',
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.request_body',
            'checks.1.request_body',
        ]);
});

test('package sync accepts raw string request bodies', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'raw-search',
                    'type' => 'api',
                    'name' => 'Raw Search API',
                    'method' => 'POST',
                    'url' => '/api/search',
                    'request_body_type' => 'raw',
                    'request_body' => 'ids=1,2,3',
                ],
            ],
        ]))
        ->assertCreated();
});

test('package sync allows request bodies at the configured size limit', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'google-maps-search',
                    'type' => 'api',
                    'name' => 'Google Maps search API',
                    'method' => 'POST',
                    'url' => '/api/google-maps/search',
                    'request_body_type' => 'raw',
                    'request_body' => str_repeat('a', 65535),
                ],
            ],
        ]))
        ->assertCreated();
});

test('package sync rejects non string schedules without throwing', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'google-maps-search',
                    'type' => 'api',
                    'name' => 'Google Maps search API',
                    'method' => 'GET',
                    'url' => '/api/google-maps/search',
                    'schedule' => ['every_5_minutes'],
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.schedule',
        ]);
});

test('package sync counts reserved non api check types as unsupported', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'certificate',
                    'type' => 'ssl',
                    'name' => 'Certificate',
                    'method' => null,
                    'url' => 'https://api.scrappa.co',
                ],
            ],
        ]));

    $response->assertCreated()
        ->assertJsonPath('data.summary.created', 0)
        ->assertJsonPath('data.summary.unsupported', 1);

    $this->assertDatabaseMissing('monitor_apis', [
        'package_name' => 'certificate',
    ]);
});

test('package sync ignores unsupported check schedules instead of validating them', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'certificate',
                    'type' => 'ssl',
                    'name' => 'Certificate',
                    'method' => null,
                    'url' => 'https://api.scrappa.co',
                    'schedule' => 'daily',
                ],
            ],
        ]));

    $response->assertCreated()
        ->assertJsonPath('data.summary.created', 0)
        ->assertJsonPath('data.summary.unsupported', 1);

    $this->assertDatabaseMissing('monitor_apis', [
        'package_name' => 'certificate',
    ]);
});

test('package sync can claim an existing project by identity endpoint fallback', function () {
    $project = Project::factory()->create([
        'created_by' => $this->user->id,
        'environment' => 'production',
        'identity_endpoint' => 'https://api.scrappa.co',
        'package_key' => null,
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload());

    $response->assertOk()
        ->assertJsonPath('data.project.id', $project->id);

    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'package_key' => 'scrappa',
        'base_url' => 'https://api.scrappa.co',
    ]);
});

test('package sync restores soft deleted api checks when re-added', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload())
        ->assertCreated();

    $monitor = MonitorApis::query()->where('package_name', 'google-maps-search')->sole();
    $monitor->delete();

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload());

    $response->assertOk()
        ->assertJsonPath('data.summary.updated', 1);

    $this->assertDatabaseHas('monitor_apis', [
        'id' => $monitor->id,
        'deleted_at' => null,
    ]);
});

test('package sync replaces assertions when a check changes', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'assertions' => [
                        ['type' => 'json_path_exists', 'path' => '$.data'],
                        ['type' => 'json_path_exists', 'path' => '$.meta'],
                    ],
                ],
            ],
        ]))
        ->assertCreated();

    $monitor = MonitorApis::query()->where('package_name', 'google-maps-search')->sole();

    expect(MonitorApiAssertion::query()->where('monitor_api_id', $monitor->id)->count())->toBe(2);

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'assertions' => [
                        ['type' => 'json_path_exists', 'path' => '$.items'],
                    ],
                ],
            ],
        ]))
        ->assertOk();

    $assertions = MonitorApiAssertion::query()
        ->where('monitor_api_id', $monitor->id)
        ->get();

    expect($assertions)->toHaveCount(1)
        ->and($assertions->first()->data_path)->toBe('items');
});

test('package sync lets checks suppress default headers with null values', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'headers' => [
                        'authorization' => null,
                        'X-Api-Key' => 'secret-package-token',
                    ],
                ],
            ],
        ]))
        ->assertCreated();

    $monitor = MonitorApis::query()->where('package_name', 'google-maps-search')->sole();

    expect($monitor->headers)->toBe([
        'Accept' => 'application/json',
        'X-Api-Key' => 'secret-package-token',
    ]);
});

test('package sync lets checks override default headers case insensitively', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'headers' => [
                        'authorization' => 'Bearer check-secret',
                        'X-Api-Key' => null,
                    ],
                ],
            ],
        ]))
        ->assertCreated();

    $monitor = MonitorApis::query()->where('package_name', 'google-maps-search')->sole();

    expect($monitor->headers)->toBe([
        'Accept' => 'application/json',
        'authorization' => 'Bearer check-secret',
    ]);
});
