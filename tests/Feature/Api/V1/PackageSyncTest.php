<?php

use App\Models\ApiKey;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\PackageSyncService;
use Illuminate\Database\QueryException;
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
        ->assertJsonPath('data.summary.disabled_missing', 0)
        ->assertJsonPath('data.summary.api_checks.created', 1)
        ->assertJsonPath('data.summary.uptime_checks.created', 0)
        ->assertJsonPath('data.summary.ssl_checks.created', 0);

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

test('package sync persists api failed response body preference', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'login',
                    'type' => 'api',
                    'name' => 'Login API',
                    'method' => 'POST',
                    'url' => '/api/login',
                    'save_failed_response' => false,
                    'schedule' => '5m',
                ],
            ],
        ]));

    $response->assertCreated();

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $response->json('data.project.id'),
        'package_name' => 'login',
        'save_failed_response' => false,
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

test('package sync rejects invalid failed response body preference', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'google-maps-search',
                    'type' => 'api',
                    'name' => 'Google Maps search API',
                    'method' => 'GET',
                    'url' => '/api/google-maps/search',
                    'save_failed_response' => 'not boolean',
                    'schedule' => 'every_5_minutes',
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.save_failed_response',
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

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'empty-json-login',
                    'type' => 'api',
                    'name' => 'Empty JSON login API',
                    'method' => 'POST',
                    'url' => '/api/login',
                    'request_body_type' => null,
                    'request_body' => [],
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

test('package sync rejects non string raw request bodies', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'raw-search',
                    'type' => 'api',
                    'name' => 'Raw Search API',
                    'method' => 'POST',
                    'url' => '/api/search',
                    'request_body_type' => 'raw',
                    'request_body' => ['ids' => [1, 2, 3]],
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.request_body',
        ]);
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

test('package sync creates website backed uptime and ssl check definitions', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage-uptime',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => 'every_5_minutes',
                ],
                [
                    'key' => 'certificate',
                    'type' => 'ssl',
                    'name' => 'Certificate',
                    'url' => 'https://api.scrappa.co',
                    'schedule' => '1d',
                ],
            ],
        ]));

    $response->assertCreated()
        ->assertJsonPath('data.summary.created', 2)
        ->assertJsonPath('data.summary.unsupported', 0)
        ->assertJsonPath('data.summary.uptime_checks.created', 1)
        ->assertJsonPath('data.summary.ssl_checks.created', 1);

    $this->assertDatabaseHas('websites', [
        'project_id' => $response->json('data.project.id'),
        'package_name' => 'homepage-uptime',
        'name' => 'Homepage uptime',
        'url' => 'https://api.scrappa.co/',
        'uptime_check' => true,
        'ssl_check' => false,
        'uptime_interval' => 5,
        'package_interval' => '5m',
        'source' => 'package',
    ]);

    $this->assertDatabaseHas('websites', [
        'project_id' => $response->json('data.project.id'),
        'package_name' => 'certificate',
        'name' => 'Certificate',
        'url' => 'https://api.scrappa.co',
        'uptime_check' => false,
        'ssl_check' => true,
        'uptime_interval' => null,
        'package_interval' => '1d',
        'source' => 'package',
    ]);

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$response->json('data.project.id')}/checks/certificate")
        ->assertOk()
        ->assertJsonPath('data.type', 'ssl')
        ->assertJsonPath('data.interval', '1d')
        ->assertJsonPath('data.interval_minutes', 1440);
});

test('package sync treats absolute website urls case insensitively', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'external-status',
                    'type' => 'uptime',
                    'name' => 'External status',
                    'url' => 'HTTPS://status.example.com',
                    'schedule' => '5m',
                ],
            ],
        ]));

    $response->assertCreated();

    $this->assertDatabaseHas('websites', [
        'project_id' => $response->json('data.project.id'),
        'package_name' => 'external-status',
        'url' => 'HTTPS://status.example.com',
        'uptime_check' => true,
        'source' => 'package',
    ]);
});

test('package website key index does not constrain manual websites', function () {
    $project = Project::factory()->create(['created_by' => $this->user->id]);

    Website::factory()
        ->count(2)
        ->create([
            'project_id' => $project->id,
            'source' => 'manual',
            'package_name' => null,
        ]);

    expect(Website::where('project_id', $project->id)->count())->toBe(2);
});

test('package website key index rejects duplicate package rows', function () {
    $project = Project::factory()->create(['created_by' => $this->user->id]);

    Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'shared-health',
    ]);

    Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'shared-health',
    ]);
})->throws(QueryException::class);

test('package website key migration keeps active duplicates and reassigns history', function () {
    $migration = require database_path('migrations/2026_04_28_010002_add_unique_package_website_key_index.php');
    $migration->down();

    $project = Project::factory()->create(['created_by' => $this->user->id]);
    $deletedWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'shared-health',
    ]);
    $activeWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'shared-health',
    ]);
    $deletedWebsite->delete();

    $history = WebsiteLogHistory::factory()->create(['website_id' => $deletedWebsite->id]);

    $migration->up();

    expect(Website::withTrashed()->find($deletedWebsite->id))->toBeNull()
        ->and(Website::find($activeWebsite->id))->not->toBeNull()
        ->and($history->fresh()->website_id)->toBe($activeWebsite->id);
});

test('package website key migration merges split duplicate check flags', function () {
    $migration = require database_path('migrations/2026_04_28_010002_add_unique_package_website_key_index.php');
    $migration->down();

    $project = Project::factory()->create(['created_by' => $this->user->id]);
    $uptimeWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'uptime_check' => true,
        'uptime_interval' => 5,
        'ssl_check' => false,
        'package_interval' => '5m',
        'updated_at' => now()->subMinute(),
    ]);
    $sslWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'uptime_check' => false,
        'uptime_interval' => null,
        'ssl_check' => true,
        'package_interval' => '1d',
        'updated_at' => now(),
    ]);

    $migration->up();

    $keptWebsite = $sslWebsite->fresh();

    expect(Website::withTrashed()->find($uptimeWebsite->id))->toBeNull()
        ->and($keptWebsite)->not->toBeNull()
        ->and($keptWebsite->uptime_check)->toBeTrue()
        ->and($keptWebsite->uptime_interval)->toBe(5)
        ->and($keptWebsite->ssl_check)->toBeTrue()
        ->and($keptWebsite->package_interval)->toBe('5m');
});

test('package website key migration ignores soft deleted duplicates when merging check flags', function () {
    $migration = require database_path('migrations/2026_04_28_010002_add_unique_package_website_key_index.php');
    $migration->down();

    $project = Project::factory()->create(['created_by' => $this->user->id]);
    $deletedUptimeWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'uptime_check' => true,
        'uptime_interval' => 5,
        'ssl_check' => false,
        'package_interval' => '5m',
    ]);
    $activeSslWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'shared-health',
        'uptime_check' => false,
        'uptime_interval' => null,
        'ssl_check' => true,
        'package_interval' => '1d',
    ]);
    $deletedUptimeWebsite->delete();

    $migration->up();

    $keptWebsite = $activeSslWebsite->fresh();

    expect(Website::withTrashed()->find($deletedUptimeWebsite->id))->toBeNull()
        ->and($keptWebsite)->not->toBeNull()
        ->and($keptWebsite->uptime_check)->toBeFalse()
        ->and($keptWebsite->uptime_interval)->toBeNull()
        ->and($keptWebsite->ssl_check)->toBeTrue()
        ->and($keptWebsite->package_interval)->toBe('1d');
});

test('package sync updates and disables missing website checks by stable package keys', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage-uptime',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '5m',
                ],
                [
                    'key' => 'certificate',
                    'type' => 'ssl',
                    'name' => 'Certificate',
                    'url' => 'https://api.scrappa.co',
                    'schedule' => '1d',
                ],
            ],
        ]))
        ->assertCreated();

    Website::query()
        ->where('package_name', 'certificate')
        ->update([
            'last_heartbeat_at' => now()->subHour(),
            'stale_at' => now()->subMinutes(5),
        ]);

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage-uptime',
                    'type' => 'uptime',
                    'name' => 'Homepage',
                    'url' => '/status',
                    'schedule' => '10m',
                ],
            ],
        ]));

    $response->assertOk()
        ->assertJsonPath('data.summary.created', 0)
        ->assertJsonPath('data.summary.updated', 1)
        ->assertJsonPath('data.summary.disabled_missing', 1)
        ->assertJsonPath('data.summary.uptime_checks.updated', 1)
        ->assertJsonPath('data.summary.ssl_checks.disabled_missing', 1);

    expect(DB::table('websites')->where('package_name', 'homepage-uptime')->count())->toBe(1)
        ->and(DB::table('websites')->where('package_name', 'certificate')->count())->toBe(1);

    $this->assertDatabaseHas('websites', [
        'package_name' => 'homepage-uptime',
        'name' => 'Homepage',
        'url' => 'https://api.scrappa.co/status',
        'uptime_check' => true,
        'uptime_interval' => 10,
        'package_interval' => '10m',
    ]);

    $this->assertDatabaseHas('websites', [
        'package_name' => 'certificate',
        'ssl_check' => false,
        'package_interval' => null,
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'deleted_at' => null,
    ]);
});

test('package sync restores soft deleted website checks when re-added', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage-uptime',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '5m',
                ],
            ],
        ]))
        ->assertCreated();

    $website = Website::query()->where('package_name', 'homepage-uptime')->sole();
    $website->delete();

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage-uptime',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '5m',
                ],
            ],
        ]));

    $response->assertOk()
        ->assertJsonPath('data.summary.updated', 1);

    $this->assertDatabaseHas('websites', [
        'id' => $website->id,
        'deleted_at' => null,
    ]);
});

test('package sync does not inherit uptime flag when restoring a deleted website via ssl-only sync', function () {
    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage',
                    'type' => 'uptime',
                    'name' => 'Homepage',
                    'url' => '/',
                    'schedule' => '5m',
                ],
                [
                    'key' => 'homepage',
                    'type' => 'ssl',
                    'name' => 'Homepage',
                    'url' => '/',
                    'schedule' => '1d',
                ],
            ],
        ]))
        ->assertCreated();

    Website::query()->where('package_name', 'homepage')->delete();

    $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage',
                    'type' => 'ssl',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '1d',
                ],
            ],
        ]))
        ->assertOk();

    $this->assertDatabaseHas('websites', [
        'package_name' => 'homepage',
        'uptime_check' => false,
        'ssl_check' => true,
        'deleted_at' => null,
    ]);
});

test('package sync allows uptime and ssl checks to share a key through the api', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '5m',
                ],
                [
                    'key' => 'homepage',
                    'type' => 'ssl',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '1d',
                ],
            ],
        ]));

    $response->assertCreated()
        ->assertJsonPath('data.summary.created', 2)
        ->assertJsonPath('data.summary.updated', 0)
        ->assertJsonPath('data.summary.uptime_checks.created', 1)
        ->assertJsonPath('data.summary.uptime_checks.updated', 0)
        ->assertJsonPath('data.summary.ssl_checks.created', 1)
        ->assertJsonPath('data.summary.ssl_checks.updated', 0);

    $this->assertDatabaseHas('websites', [
        'package_name' => 'homepage',
        'uptime_check' => true,
        'ssl_check' => true,
        'uptime_interval' => 5,
        'package_interval' => '5m',
    ]);
});

test('package shared uptime and ssl key can be read as the primary uptime check', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '5m',
                ],
                [
                    'key' => 'homepage',
                    'type' => 'ssl',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '1d',
                ],
            ],
        ]));

    $response->assertCreated();

    $projectId = $response->json('data.project.id');
    $website = Website::query()->where('package_name', 'homepage')->sole();

    $this->withToken($this->apiKey->key)
        ->getJson("/api/v1/projects/{$projectId}/checks/homepage")
        ->assertOk()
        ->assertJsonPath('data.id', "uptime:{$website->id}")
        ->assertJsonPath('data.key', 'homepage')
        ->assertJsonPath('data.type', 'uptime');
});

test('package sync accepts equivalent normalized urls for shared uptime and ssl keys', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '5m',
                ],
                [
                    'key' => 'homepage',
                    'type' => 'ssl',
                    'name' => 'Homepage uptime',
                    'url' => 'HTTPS://API.SCRAPPA.CO:443',
                    'schedule' => '1d',
                ],
            ],
        ]));

    $response->assertCreated();

    $this->assertDatabaseHas('websites', [
        'package_name' => 'homepage',
        'url' => 'HTTPS://API.SCRAPPA.CO:443',
        'uptime_check' => true,
        'ssl_check' => true,
    ]);
});

test('package sync rejects shared uptime and ssl keys with different urls', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/health',
                    'schedule' => '5m',
                ],
                [
                    'key' => 'homepage',
                    'type' => 'ssl',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '1d',
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.1.url',
        ]);
});

test('package sync rejects shared uptime and ssl keys with different names', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '5m',
                ],
                [
                    'key' => 'homepage',
                    'type' => 'ssl',
                    'name' => 'Homepage certificate',
                    'url' => '/',
                    'schedule' => '1d',
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.1.name',
        ]);
});

test('package sync rejects duplicate keys within the same check type', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '5m',
                ],
                [
                    'key' => 'homepage',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime duplicate',
                    'url' => '/duplicate',
                    'schedule' => '5m',
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.1.key',
        ]);
});

test('package sync rejects shared keys outside the uptime ssl pair', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'health',
                    'type' => 'api',
                    'name' => 'Health API',
                    'method' => 'GET',
                    'url' => '/health',
                ],
                [
                    'key' => 'health',
                    'type' => 'uptime',
                    'name' => 'Health uptime',
                    'url' => '/health',
                    'schedule' => '5m',
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.1.key',
        ]);
});

test('package sync service preserves website flags when uptime and ssl share a key', function () {
    app(PackageSyncService::class)->sync($this->user, packageSyncPayload([
        'checks' => [
            [
                'key' => 'homepage',
                'type' => 'uptime',
                'name' => 'Homepage',
                'url' => '/',
                'schedule' => '5m',
            ],
            [
                'key' => 'homepage',
                'type' => 'ssl',
                'name' => 'Homepage certificate',
                'url' => '/',
                'schedule' => '1d',
            ],
        ],
    ]));

    $website = Website::query()->where('package_name', 'homepage')->sole();

    expect($website->uptime_check)->toBeTrue()
        ->and($website->ssl_check)->toBeTrue()
        ->and($website->uptime_interval)->toBe(5)
        ->and($website->package_interval)->toBe('5m');
});

test('package sync keeps package interval while a shared-key website check remains active', function () {
    app(PackageSyncService::class)->sync($this->user, packageSyncPayload([
        'checks' => [
            [
                'key' => 'homepage',
                'type' => 'uptime',
                'name' => 'Homepage',
                'url' => '/',
                'schedule' => '5m',
            ],
            [
                'key' => 'homepage',
                'type' => 'ssl',
                'name' => 'Homepage certificate',
                'url' => '/',
                'schedule' => '1d',
            ],
        ],
    ]));

    app(PackageSyncService::class)->sync($this->user, packageSyncPayload([
        'checks' => [
            [
                'key' => 'homepage',
                'type' => 'ssl',
                'name' => 'Homepage certificate',
                'url' => '/',
                'schedule' => '1d',
            ],
        ],
    ]));

    $website = Website::query()->where('package_name', 'homepage')->sole();

    expect($website->uptime_check)->toBeFalse()
        ->and($website->ssl_check)->toBeTrue()
        ->and($website->package_interval)->toBe('1d');
});

test('package sync recalculates package interval when ssl is removed from a shared-key website', function () {
    app(PackageSyncService::class)->sync($this->user, packageSyncPayload([
        'checks' => [
            [
                'key' => 'homepage',
                'type' => 'uptime',
                'name' => 'Homepage',
                'url' => '/',
                'schedule' => '5m',
            ],
            [
                'key' => 'homepage',
                'type' => 'ssl',
                'name' => 'Homepage certificate',
                'url' => '/',
                'schedule' => '1d',
            ],
        ],
    ]));

    app(PackageSyncService::class)->sync($this->user, packageSyncPayload([
        'checks' => [
            [
                'key' => 'homepage',
                'type' => 'uptime',
                'name' => 'Homepage',
                'url' => '/',
                'schedule' => '30m',
            ],
        ],
    ]));

    $website = Website::query()->where('package_name', 'homepage')->sole();

    expect($website->uptime_check)->toBeTrue()
        ->and($website->ssl_check)->toBeFalse()
        ->and($website->uptime_interval)->toBe(30)
        ->and($website->package_interval)->toBe('30m');
});

test('package sync uses uptime interval for shared-key package stale detection', function () {
    app(PackageSyncService::class)->sync($this->user, packageSyncPayload([
        'checks' => [
            [
                'key' => 'homepage',
                'type' => 'uptime',
                'name' => 'Homepage',
                'url' => '/',
                'schedule' => '1h',
            ],
            [
                'key' => 'homepage',
                'type' => 'ssl',
                'name' => 'Homepage certificate',
                'url' => '/',
                'schedule' => '5m',
            ],
        ],
    ]));

    $website = Website::query()->where('package_name', 'homepage')->sole();

    expect($website->uptime_check)->toBeTrue()
        ->and($website->ssl_check)->toBeTrue()
        ->and($website->uptime_interval)->toBe(60)
        ->and($website->package_interval)->toBe('1h');
});

test('package sync keeps status while a shared-key website check remains active', function () {
    app(PackageSyncService::class)->sync($this->user, packageSyncPayload([
        'checks' => [
            [
                'key' => 'homepage',
                'type' => 'uptime',
                'name' => 'Homepage',
                'url' => '/',
                'schedule' => '5m',
            ],
            [
                'key' => 'homepage',
                'type' => 'ssl',
                'name' => 'Homepage certificate',
                'url' => '/',
                'schedule' => '1d',
            ],
        ],
    ]));

    Website::query()
        ->where('package_name', 'homepage')
        ->update([
            'current_status' => 'success',
            'status_summary' => 'Certificate healthy.',
        ]);

    app(PackageSyncService::class)->sync($this->user, packageSyncPayload([
        'checks' => [
            [
                'key' => 'homepage',
                'type' => 'ssl',
                'name' => 'Homepage certificate',
                'url' => '/',
                'schedule' => '1d',
            ],
        ],
    ]));

    $website = Website::query()->where('package_name', 'homepage')->sole();

    expect($website->uptime_check)->toBeFalse()
        ->and($website->ssl_check)->toBeTrue()
        ->and($website->current_status)->toBe('success')
        ->and($website->status_summary)->toBe('Certificate healthy.');
});

test('package sync rejects check types that are not implemented by the package sync API', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage-links',
                    'type' => 'links',
                    'name' => 'Homepage links',
                    'url' => '/',
                ],
                [
                    'key' => 'homepage-og',
                    'type' => 'opengraph',
                    'name' => 'Homepage Open Graph',
                    'url' => '/',
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.type',
            'checks.1.type',
        ]);
});

test('package sync requires valid schedules for uptime and ssl checks', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage-uptime',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => null,
                ],
                [
                    'key' => 'certificate',
                    'type' => 'ssl',
                    'name' => 'Certificate',
                    'url' => 'https://api.scrappa.co',
                    'schedule' => 'daily',
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.schedule',
            'checks.1.schedule',
        ]);
});

test('package sync requires schedule keys for uptime and ssl checks', function () {
    $payload = packageSyncPayload();
    $payload['checks'] = [
        [
            'key' => 'homepage-uptime',
            'type' => 'uptime',
            'name' => 'Homepage uptime',
            'url' => '/',
        ],
        [
            'key' => 'certificate',
            'type' => 'ssl',
            'name' => 'Certificate',
            'url' => 'https://api.scrappa.co',
        ],
    ];

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', $payload);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.schedule',
            'checks.1.schedule',
        ]);
});

test('package sync rejects website schedules that cannot be executed as configured', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/sync', packageSyncPayload([
            'checks' => [
                [
                    'key' => 'homepage-uptime',
                    'type' => 'uptime',
                    'name' => 'Homepage uptime',
                    'url' => '/',
                    'schedule' => '2m',
                ],
                [
                    'key' => 'certificate',
                    'type' => 'ssl',
                    'name' => 'Certificate',
                    'url' => 'https://api.scrappa.co',
                    'schedule' => '2m',
                ],
                [
                    'key' => 'seconds-uptime',
                    'type' => 'uptime',
                    'name' => 'Seconds uptime',
                    'url' => '/seconds',
                    'schedule' => '30s',
                ],
                [
                    'key' => 'seconds-certificate',
                    'type' => 'ssl',
                    'name' => 'Seconds certificate',
                    'url' => 'https://api.scrappa.co',
                    'schedule' => '30s',
                ],
            ],
        ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'checks.0.schedule',
            'checks.2.schedule',
            'checks.3.schedule',
        ])
        ->assertJsonMissingValidationErrors([
            'checks.1.schedule',
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
