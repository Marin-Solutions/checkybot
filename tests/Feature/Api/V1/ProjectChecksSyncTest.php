<?php

use App\Models\ApiKey;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\CheckSyncService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);
    $this->project = Project::factory()->create(['created_by' => $this->user->id]);
    $this->syncService = app(CheckSyncService::class);
});

test('syncs uptime checks successfully', function () {
    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            [
                'name' => 'homepage-uptime',
                'url' => 'https://uptime-example.com',
                'interval' => '5m',
                'max_redirects' => 10,
            ],
        ],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 1,
        'updated' => 0,
        'deleted' => 0,
    ]);

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'name' => 'homepage-uptime',
        'url' => 'https://uptime-example.com',
        'uptime_check' => true,
        'uptime_interval' => 5,
        'source' => 'package',
        'package_name' => 'homepage-uptime',
        'package_interval' => '5m',
    ]);
});

test('syncs ssl checks successfully', function () {
    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [
            [
                'name' => 'homepage-ssl',
                'url' => 'https://ssl-example.com',
                'interval' => '1d',
            ],
        ],
        'api_checks' => [],
    ]);

    expect($summary['ssl_checks'])->toBe([
        'created' => 1,
        'updated' => 0,
        'deleted' => 0,
    ]);

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'name' => 'homepage-ssl',
        'url' => 'https://ssl-example.com',
        'ssl_check' => true,
        'source' => 'package',
        'package_name' => 'homepage-ssl',
        'package_interval' => '1d',
    ]);
});

test('syncs api checks with assertions successfully', function () {
    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [
            [
                'name' => 'health-check',
                'url' => 'https://api.example.com/health',
                'interval' => '5m',
                'method' => 'POST',
                'expected_status' => 202,
                'timeout_seconds' => 15,
                'enabled' => false,
                'headers' => [
                    'Authorization' => 'Bearer token',
                ],
                'request_body_type' => 'form',
                'request_body' => [
                    'grant_type' => 'client_credentials',
                    'scope' => 'health',
                ],
                'assertions' => [
                    [
                        'data_path' => 'status',
                        'assertion_type' => 'exists',
                        'sort_order' => 1,
                        'is_active' => true,
                    ],
                    [
                        'data_path' => 'count',
                        'assertion_type' => 'value_compare',
                        'comparison_operator' => '>=',
                        'expected_value' => '1',
                        'sort_order' => 2,
                        'is_active' => true,
                    ],
                ],
            ],
        ],
    ]);

    expect($summary['api_checks'])->toBe([
        'created' => 1,
        'updated' => 0,
        'deleted' => 0,
    ]);

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'title' => 'health-check',
        'url' => 'https://api.example.com/health',
        'http_method' => 'POST',
        'request_path' => 'https://api.example.com/health',
        'expected_status' => 202,
        'timeout_seconds' => 15,
        'package_schedule' => '5m',
        'is_enabled' => false,
        'source' => 'package',
        'package_name' => 'health-check',
        'package_interval' => '5m',
    ]);

    $api = MonitorApis::where('package_name', 'health-check')->first();
    expect($api->assertions)->toHaveCount(2)
        ->and($api->request_body_type)->toBe('form')
        ->and($api->request_body)->toBe('{"grant_type":"client_credentials","scope":"health"}');
});

test('updates api checks with execution settings from legacy sync', function () {
    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'health-check',
        'url' => 'https://api.example.com/old-health',
        'http_method' => 'GET',
        'expected_status' => 200,
        'timeout_seconds' => 5,
        'is_enabled' => true,
        'source' => 'package',
        'package_name' => 'health-check',
        'package_interval' => '5m',
        'created_by' => $this->user->id,
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [
            [
                'name' => 'health-check',
                'url' => 'https://api.example.com/health',
                'interval' => '10m',
                'method' => 'patch',
                'expected_status' => 204,
                'timeout_seconds' => 30,
                'save_failed_response' => false,
                'enabled' => false,
            ],
        ],
    ]);

    expect($summary['api_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'health-check',
        'url' => 'https://api.example.com/health',
        'http_method' => 'PATCH',
        'request_path' => 'https://api.example.com/health',
        'expected_status' => 204,
        'timeout_seconds' => 30,
        'save_failed_response' => false,
        'package_schedule' => '10m',
        'package_interval' => '10m',
        'is_enabled' => false,
    ]);
});

test('preserves existing api execution settings when legacy sync omits them', function () {
    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'health-check',
        'url' => 'https://api.example.com/old-health',
        'http_method' => 'PATCH',
        'expected_status' => 204,
        'timeout_seconds' => 30,
        'save_failed_response' => false,
        'is_enabled' => false,
        'source' => 'package',
        'package_name' => 'health-check',
        'package_interval' => '5m',
        'created_by' => $this->user->id,
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [
            [
                'name' => 'health-check',
                'url' => 'https://api.example.com/health',
                'interval' => '10m',
            ],
        ],
    ]);

    expect($summary['api_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'health-check',
        'url' => 'https://api.example.com/health',
        'http_method' => 'PATCH',
        'expected_status' => 204,
        'timeout_seconds' => 30,
        'save_failed_response' => false,
        'is_enabled' => false,
        'package_interval' => '10m',
    ]);
});

test('re-enables orphaned api checks when legacy sync reintroduces them without enabled flag', function () {
    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [
            [
                'name' => 'health-check',
                'url' => 'https://api.example.com/health',
                'interval' => '5m',
            ],
        ],
    ]);

    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'health-check',
        'is_enabled' => false,
        'current_status' => 'unknown',
        'status_summary' => 'Disabled because it was missing from the latest package sync.',
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'deleted_at' => null,
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [
            [
                'name' => 'health-check',
                'url' => 'https://api.example.com/health',
                'interval' => '10m',
            ],
        ],
    ]);

    expect($summary['api_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'health-check',
        'url' => 'https://api.example.com/health',
        'package_interval' => '10m',
        'is_enabled' => true,
        'current_status' => 'unknown',
        'status_summary' => null,
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'deleted_at' => null,
    ]);
});

test('clears missing sync evidence when orphaned api checks return disabled', function () {
    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [
            [
                'name' => 'optional-health-check',
                'url' => 'https://api.example.com/optional-health',
                'interval' => '5m',
            ],
        ],
    ]);

    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [
            [
                'name' => 'optional-health-check',
                'url' => 'https://api.example.com/optional-health',
                'interval' => '10m',
                'enabled' => false,
            ],
        ],
    ]);

    expect($summary['api_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'optional-health-check',
        'package_interval' => '10m',
        'is_enabled' => false,
        'current_status' => 'unknown',
        'status_summary' => null,
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'deleted_at' => null,
    ]);
});

test('legacy sync defaults nullable expected status to success status', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'nullable-status-api',
                    'url' => 'https://api.example.com/status',
                    'interval' => '5m',
                    'expected_status' => null,
                ],
            ],
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'nullable-status-api',
        'expected_status' => 200,
    ]);
});

test('updates existing checks', function () {
    Website::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'homepage-uptime',
        'url' => 'https://old-url.com',
        'uptime_check' => true,
        'source' => 'package',
        'package_name' => 'homepage-uptime',
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            [
                'name' => 'homepage-uptime',
                'url' => 'https://new-url.com',
                'interval' => '10m',
            ],
        ],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    $this->assertDatabaseHas('websites', [
        'package_name' => 'homepage-uptime',
        'url' => 'https://new-url.com',
        'uptime_interval' => 10,
    ]);
});

test('disables orphaned uptime checks without deleting them', function () {
    Website::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'old-check',
        'url' => 'https://old.com',
        'uptime_check' => true,
        'ssl_check' => false,
        'source' => 'package',
        'package_name' => 'old-check',
        'package_interval' => '5m',
        'current_status' => 'healthy',
        'last_heartbeat_at' => now(),
        'stale_at' => now()->addMinutes(10),
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            [
                'name' => 'new-check',
                'url' => 'https://new.com',
                'interval' => '5m',
            ],
        ],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 1,
        'updated' => 0,
        'deleted' => 1,
    ]);

    $this->assertDatabaseHas('websites', [
        'package_name' => 'old-check',
        'uptime_check' => false,
        'ssl_check' => false,
        'package_interval' => null,
        'current_status' => 'unknown',
        'status_summary' => 'Disabled because it was missing from the latest package sync.',
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'deleted_at' => null,
    ]);
    $this->assertDatabaseHas('websites', ['package_name' => 'new-check']);
});

test('re-enables orphaned website checks without stale disabled evidence', function () {
    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            [
                'name' => 'homepage',
                'url' => 'https://example.com',
                'interval' => '5m',
            ],
        ],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'homepage',
        'uptime_check' => false,
        'ssl_check' => false,
        'current_status' => 'unknown',
        'status_summary' => 'Disabled because it was missing from the latest package sync.',
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'deleted_at' => null,
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            [
                'name' => 'homepage',
                'url' => 'https://example.com',
                'interval' => '10m',
            ],
        ],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'uptime_interval' => 10,
        'current_status' => 'unknown',
        'status_summary' => null,
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'deleted_at' => null,
    ]);
});

test('disables orphaned package-managed checks and preserves their history', function () {
    $website = Website::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'old-check',
        'url' => 'https://old.com',
        'uptime_check' => true,
        'ssl_check' => false,
        'source' => 'package',
        'package_name' => 'old-check',
        'package_interval' => '5m',
        'current_status' => 'danger',
        'status_summary' => 'HTTP 500',
        'last_heartbeat_at' => now(),
        'stale_at' => now()->addMinutes(10),
    ]);

    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'old-api',
        'url' => 'https://old-api.com',
        'source' => 'package',
        'package_name' => 'old-api',
        'package_interval' => '5m',
        'is_enabled' => true,
        'current_status' => 'danger',
        'status_summary' => 'Expected 200, got 500.',
        'last_heartbeat_at' => now(),
        'stale_at' => now()->addMinutes(10),
        'created_by' => $this->user->id,
    ]);

    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
    ]);

    MonitorApiResult::factory()->create([
        'monitor_api_id' => $api->id,
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks']['deleted'])->toBe(1);
    expect($summary['api_checks']['deleted'])->toBe(1);

    $this->assertDatabaseHas('websites', [
        'id' => $website->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'package_interval' => null,
        'current_status' => 'unknown',
        'status_summary' => 'Disabled because it was missing from the latest package sync.',
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'deleted_at' => null,
    ]);

    $this->assertDatabaseHas('monitor_apis', [
        'id' => $api->id,
        'is_enabled' => false,
        'current_status' => 'unknown',
        'status_summary' => 'Disabled because it was missing from the latest package sync.',
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'deleted_at' => null,
    ]);

    $this->assertDatabaseHas('website_log_history', [
        'website_id' => $website->id,
    ]);

    $this->assertDatabaseHas('monitor_api_results', [
        'monitor_api_id' => $api->id,
    ]);
});

test('preserves manual checks during sync', function () {
    Website::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'manual-check',
        'url' => 'https://manual.com',
        'uptime_check' => true,
        'source' => 'manual',
    ]);

    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    $this->assertDatabaseHas('websites', [
        'name' => 'manual-check',
        'source' => 'manual',
    ]);
});

test('requires authentication', function () {
    $response = $this->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    $response->assertStatus(401);
});

test('requires project ownership', function () {
    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->create(['created_by' => $otherUser->id]);
    $apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);

    $response = $this->withToken($apiKey->key)
        ->postJson("/api/v1/projects/{$otherProject->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [],
        ]);

    $response->assertStatus(403);
});

test('validates interval format', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [
                [
                    'name' => 'test',
                    'url' => 'https://example.com',
                    'interval' => 'invalid',
                ],
            ],
            'ssl_checks' => [],
            'api_checks' => [],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['uptime_checks.0.interval']);
});

test('accepts interval parser formats at the request boundary', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [
                [
                    'name' => 'fast-uptime',
                    'url' => 'https://example.com',
                    'interval' => '30s',
                ],
            ],
            'ssl_checks' => [
                [
                    'name' => 'ssl-certificate',
                    'url' => 'https://example.com',
                    'interval' => 'every_5_minutes',
                ],
            ],
            'api_checks' => [
                [
                    'name' => 'api-health',
                    'url' => 'https://api.example.com/health',
                    'interval' => 'every_1_hour',
                ],
            ],
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'fast-uptime',
        'uptime_interval' => 1,
        'package_interval' => '30s',
    ]);

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'ssl-certificate',
        'package_interval' => 'every_5_minutes',
    ]);

    $this->assertDatabaseHas('monitor_apis', [
        'project_id' => $this->project->id,
        'package_name' => 'api-health',
        'package_interval' => 'every_1_hour',
    ]);
});

test('rejects zero intervals at the request boundary', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [
                [
                    'name' => 'test',
                    'url' => 'https://example.com',
                    'interval' => '0m',
                ],
            ],
            'ssl_checks' => [
                [
                    'name' => 'ssl-test',
                    'url' => 'https://example.com',
                    'interval' => '0m',
                ],
            ],
            'api_checks' => [
                [
                    'name' => 'api-test',
                    'url' => 'https://api.example.com',
                    'interval' => '0m',
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors([
            'uptime_checks.0.interval',
            'ssl_checks.0.interval',
            'api_checks.0.interval',
        ]);
});

test('rejects unsupported legacy check families at the request boundary', function (array $linkChecks, array $openGraphChecks) {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [],
            'link_checks' => $linkChecks,
            'open_graph_checks' => $openGraphChecks,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors([
            'link_checks',
            'open_graph_checks',
        ])
        ->assertJsonPath('errors.link_checks.0', 'link_checks are not supported by project check sync yet.')
        ->assertJsonPath('errors.open_graph_checks.0', 'open_graph_checks are not supported by project check sync yet.');
})->with([
    'populated unsupported families' => [
        [
            [
                'name' => 'homepage-links',
                'url' => 'https://example.com',
                'interval' => '1d',
            ],
        ],
        [
            [
                'name' => 'homepage-og',
                'url' => 'https://example.com',
                'interval' => '1d',
            ],
        ],
    ],
    'empty unsupported families' => [
        [],
        [],
    ],
]);

test('validates url format', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [
                [
                    'name' => 'test',
                    'url' => 'not-a-url',
                    'interval' => '5m',
                ],
            ],
            'ssl_checks' => [],
            'api_checks' => [],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['uptime_checks.0.url']);
});

test('validates required fields', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [
                [
                    'url' => 'https://example.com',
                ],
            ],
            'ssl_checks' => [],
            'api_checks' => [],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors([
            'uptime_checks.0.name',
            'uptime_checks.0.interval',
        ]);
});

test('validates check names do not contain slashes', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [
                [
                    'name' => 'homepage/uptime',
                    'url' => 'https://example.com',
                    'interval' => '5m',
                ],
            ],
            'ssl_checks' => [
                [
                    'name' => 'homepage/ssl',
                    'url' => 'https://example.com',
                    'interval' => '1d',
                ],
            ],
            'api_checks' => [
                [
                    'name' => 'api/health',
                    'url' => 'https://api.example.com/health',
                    'interval' => '5m',
                ],
            ],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'uptime_checks.0.name',
            'ssl_checks.0.name',
            'api_checks.0.name',
        ]);
});

test('validates assertion types', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'test',
                    'url' => 'https://api.example.com',
                    'interval' => '5m',
                    'assertions' => [
                        [
                            'data_path' => 'status',
                            'assertion_type' => 'invalid_type',
                        ],
                    ],
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['api_checks.0.assertions.0.assertion_type']);
});

test('validates regex assertion patterns before syncing api checks', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'regex-health',
                    'url' => 'https://api.example.com/health',
                    'interval' => '5m',
                    'assertions' => [
                        [
                            'data_path' => 'status',
                            'assertion_type' => 'regex_match',
                            'regex_pattern' => '/[unterminated/',
                        ],
                    ],
                ],
            ],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['api_checks.0.assertions.0.regex_pattern']);

    $this->assertDatabaseMissing('monitor_apis', [
        'package_name' => 'regex-health',
    ]);
});

test('validates expected value shapes before syncing api checks', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'array-expected-value',
                    'url' => 'https://api.example.com/health',
                    'interval' => '5m',
                    'assertions' => [
                        [
                            'data_path' => 'status',
                            'assertion_type' => 'value_compare',
                            'comparison_operator' => '=',
                            'expected_value' => ['ok'],
                        ],
                    ],
                ],
            ],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['api_checks.0.assertions.0.expected_value']);

    $this->assertDatabaseMissing('monitor_apis', [
        'package_name' => 'array-expected-value',
    ]);
});

test('validates unused expected value shapes before syncing api checks', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'unused-array-expected-value',
                    'url' => 'https://api.example.com/health',
                    'interval' => '5m',
                    'assertions' => [
                        [
                            'data_path' => 'status',
                            'assertion_type' => 'exists',
                            'expected_value' => ['ok'],
                        ],
                    ],
                ],
            ],
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['api_checks.0.assertions.0.expected_value']);

    $this->assertDatabaseMissing('monitor_apis', [
        'package_name' => 'unused-array-expected-value',
    ]);
});

test('validates api check execution settings', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'bad-method',
                    'url' => 'https://api.example.com/method',
                    'interval' => '5m',
                    'method' => 'CONNECT',
                ],
                [
                    'name' => 'bad-low-status',
                    'url' => 'https://api.example.com/status-low',
                    'interval' => '5m',
                    'expected_status' => 99,
                ],
                [
                    'name' => 'bad-high-status',
                    'url' => 'https://api.example.com/status-high',
                    'interval' => '5m',
                    'expected_status' => 600,
                ],
                [
                    'name' => 'bad-low-timeout',
                    'url' => 'https://api.example.com/timeout-low',
                    'interval' => '5m',
                    'timeout_seconds' => 0,
                ],
                [
                    'name' => 'bad-high-timeout',
                    'url' => 'https://api.example.com/timeout-high',
                    'interval' => '5m',
                    'timeout_seconds' => 121,
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors([
            'api_checks.0.method',
            'api_checks.1.expected_status',
            'api_checks.2.expected_status',
            'api_checks.3.timeout_seconds',
            'api_checks.4.timeout_seconds',
        ]);
});

test('requires body type when an api check request body is provided', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'login-api',
                    'url' => 'https://api.example.com/login',
                    'interval' => '5m',
                    'request_body' => ['probe' => true],
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['api_checks.0.request_body_type']);

    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'empty-login-api',
                    'url' => 'https://api.example.com/login',
                    'interval' => '5m',
                    'request_body' => [],
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['api_checks.0.request_body_type']);
});

test('limits api check request body size', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'login-api',
                    'url' => 'https://api.example.com/login',
                    'interval' => '5m',
                    'request_body_type' => 'raw',
                    'request_body' => str_repeat('a', 65536),
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['api_checks.0.request_body']);
});

test('rejects unstructured json and form api check request bodies', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'json-login',
                    'url' => 'https://api.example.com/login',
                    'interval' => '5m',
                    'request_body_type' => 'json',
                    'request_body' => 'email=monitor@example.com',
                ],
                [
                    'name' => 'form-token',
                    'url' => 'https://api.example.com/token',
                    'interval' => '5m',
                    'request_body_type' => 'form',
                    'request_body' => 'grant_type=client_credentials',
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors([
            'api_checks.0.request_body',
            'api_checks.1.request_body',
        ]);
});

test('rejects non string raw api check request bodies', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [
                [
                    'name' => 'raw-login-api',
                    'url' => 'https://api.example.com/login',
                    'interval' => '5m',
                    'request_body_type' => 'raw',
                    'request_body' => ['probe' => true],
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['api_checks.0.request_body']);
});

test('syncs multiple check types atomically', function () {
    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'uptime-1', 'url' => 'https://uptime-example.com', 'interval' => '5m'],
        ],
        'ssl_checks' => [
            ['name' => 'ssl-1', 'url' => 'https://ssl-example.com', 'interval' => '1d'],
        ],
        'api_checks' => [
            ['name' => 'api-1', 'url' => 'https://api.example.com', 'interval' => '5m'],
        ],
    ]);

    $this->assertDatabaseHas('websites', ['package_name' => 'uptime-1', 'uptime_check' => true]);
    $this->assertDatabaseHas('websites', ['package_name' => 'ssl-1', 'ssl_check' => true]);
    $this->assertDatabaseHas('monitor_apis', ['package_name' => 'api-1']);
});

test('legacy sync stamps project and check sync metadata', function () {
    $syncedAt = Carbon::parse('2026-05-01 12:00:00');

    Carbon::setTestNow($syncedAt);

    try {
        $this->syncService->syncChecks($this->project, [
            'uptime_checks' => [
                ['name' => 'uptime-1', 'url' => 'https://uptime-example.com', 'interval' => '5m'],
            ],
            'ssl_checks' => [
                ['name' => 'ssl-1', 'url' => 'https://ssl-example.com', 'interval' => '1d'],
            ],
            'api_checks' => [
                ['name' => 'api-1', 'url' => 'https://api.example.com', 'interval' => '5m'],
            ],
        ]);
    } finally {
        Carbon::setTestNow();
    }

    expect($this->project->refresh()->last_synced_at?->toISOString())->toBe($syncedAt->toISOString());

    $websites = Website::query()
        ->where('project_id', $this->project->id)
        ->whereIn('package_name', ['uptime-1', 'ssl-1'])
        ->get();

    expect($websites)->toHaveCount(2);

    $websites->each(fn (Website $website) => expect($website->last_synced_at?->toISOString())->toBe($syncedAt->toISOString()));

    expect(MonitorApis::query()
        ->where('project_id', $this->project->id)
        ->where('package_name', 'api-1')
        ->first()
        ?->last_synced_at
        ?->toISOString())->toBe($syncedAt->toISOString());
});

test('syncs uptime and ssl package checks with the same url', function () {
    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'homepage-uptime', 'url' => 'https://shared-example.com', 'interval' => '5m'],
        ],
        'ssl_checks' => [
            ['name' => 'homepage-ssl', 'url' => 'https://shared-example.com', 'interval' => '1d'],
        ],
        'api_checks' => [],
    ]);

    $this->assertDatabaseHas('websites', [
        'package_name' => 'homepage-uptime',
        'url' => 'https://shared-example.com',
        'uptime_check' => true,
        'ssl_check' => false,
    ]);

    $this->assertDatabaseHas('websites', [
        'package_name' => 'homepage-ssl',
        'url' => 'https://shared-example.com',
        'uptime_check' => false,
        'ssl_check' => true,
    ]);
});

test('syncs uptime and ssl package checks with the same package name onto one website', function () {
    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '5m'],
        ],
        'ssl_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '1d'],
        ],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 1,
        'updated' => 0,
        'deleted' => 0,
    ]);

    expect($summary['ssl_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    expect(Website::where('package_name', 'homepage')->count())->toBe(1);

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'homepage',
        'url' => 'https://shared-example.com',
        'uptime_check' => true,
        'uptime_interval' => 5,
        'ssl_check' => true,
        'source' => 'package',
    ]);
});

test('removing one shared package website check preserves the remaining check type', function () {
    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '5m'],
        ],
        'ssl_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '1d'],
        ],
        'api_checks' => [],
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '10m'],
        ],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    expect($summary['ssl_checks'])->toBe([
        'created' => 0,
        'updated' => 0,
        'deleted' => 0,
    ]);

    expect(Website::where('package_name', 'homepage')->count())->toBe(1);

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'package_interval' => '10m',
    ]);
});

test('removing uptime from a shared package website check preserves ssl monitoring', function () {
    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '5m'],
        ],
        'ssl_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '1d'],
        ],
        'api_checks' => [],
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '2d'],
        ],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 0,
        'updated' => 0,
        'deleted' => 0,
    ]);

    expect($summary['ssl_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    expect(Website::where('package_name', 'homepage')->count())->toBe(1);

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'homepage',
        'uptime_check' => false,
        'ssl_check' => true,
        'package_interval' => '2d',
    ]);
});

test('transitioning from uptime-only to ssl-only does not restore uptime from the trashed row', function () {
    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '5m'],
        ],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '1d'],
        ],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 0,
        'updated' => 0,
        'deleted' => 1,
    ]);

    expect($summary['ssl_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    expect(Website::where('package_name', 'homepage')->count())->toBe(1);

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'homepage',
        'uptime_check' => false,
        'ssl_check' => true,
        'current_status' => 'unknown',
        'status_summary' => null,
        'last_heartbeat_at' => null,
        'stale_at' => null,
    ]);
});

test('transitioning from ssl-only to uptime-only disables ssl without deleting the row', function () {
    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '1d'],
        ],
        'api_checks' => [],
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'homepage', 'url' => 'https://shared-example.com', 'interval' => '5m'],
        ],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    expect($summary['ssl_checks'])->toBe([
        'created' => 0,
        'updated' => 0,
        'deleted' => 0,
    ]);

    expect(Website::where('package_name', 'homepage')->count())->toBe(1);

    $this->assertDatabaseHas('websites', [
        'project_id' => $this->project->id,
        'package_name' => 'homepage',
        'uptime_check' => true,
        'uptime_interval' => 5,
        'ssl_check' => false,
    ]);
});

test('updates uptime and ssl package checks with the same url', function () {
    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'homepage-uptime', 'url' => 'https://shared-example.com', 'interval' => '5m'],
        ],
        'ssl_checks' => [
            ['name' => 'homepage-ssl', 'url' => 'https://shared-example.com', 'interval' => '1d'],
        ],
        'api_checks' => [],
    ]);

    $summary = $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [
            ['name' => 'homepage-uptime', 'url' => 'https://shared-example.com', 'interval' => '10m'],
        ],
        'ssl_checks' => [
            ['name' => 'homepage-ssl', 'url' => 'https://shared-example.com', 'interval' => '2d'],
        ],
        'api_checks' => [],
    ]);

    expect($summary['uptime_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    expect($summary['ssl_checks'])->toBe([
        'created' => 0,
        'updated' => 1,
        'deleted' => 0,
    ]);

    expect(Website::where('url', 'https://shared-example.com')->count())->toBe(2);

    $this->assertDatabaseHas('websites', [
        'package_name' => 'homepage-uptime',
        'url' => 'https://shared-example.com',
        'uptime_check' => true,
        'ssl_check' => false,
        'package_interval' => '10m',
    ]);

    $this->assertDatabaseHas('websites', [
        'package_name' => 'homepage-ssl',
        'url' => 'https://shared-example.com',
        'uptime_check' => false,
        'ssl_check' => true,
        'package_interval' => '2d',
    ]);
});

test('replaces assertions on api check update', function () {
    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'title' => 'health-check',
        'url' => 'https://api.example.com/health',
        'source' => 'package',
        'package_name' => 'health-check',
    ]);

    MonitorApiAssertion::factory()->count(2)->create(['monitor_api_id' => $api->id]);

    $this->syncService->syncChecks($this->project, [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [
            [
                'name' => 'health-check',
                'url' => 'https://api.example.com/health',
                'interval' => '5m',
                'assertions' => [
                    [
                        'data_path' => 'new_field',
                        'assertion_type' => 'exists',
                        'sort_order' => 1,
                        'is_active' => true,
                    ],
                ],
            ],
        ],
    ]);

    $api->refresh();
    expect($api->assertions)->toHaveCount(1);
    expect($api->assertions->first()->data_path)->toBe('new_field');
});
