<?php

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Services\CheckSyncService;

beforeEach(function () {
    $this->user = User::factory()->create();
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
                'headers' => [
                    'Authorization' => 'Bearer token',
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
        'source' => 'package',
        'package_name' => 'health-check',
        'package_interval' => '5m',
    ]);

    $api = MonitorApis::where('package_name', 'health-check')->first();
    expect($api->assertions)->toHaveCount(2);
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

test('prunes orphaned checks', function () {
    Website::factory()->create([
        'project_id' => $this->project->id,
        'name' => 'old-check',
        'url' => 'https://old.com',
        'uptime_check' => true,
        'source' => 'package',
        'package_name' => 'old-check',
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

    $this->assertDatabaseMissing('websites', ['package_name' => 'old-check']);
    $this->assertDatabaseHas('websites', ['package_name' => 'new-check']);
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

    $response = $this->actingAs($this->user)->postJson("/api/v1/projects/{$otherProject->id}/checks/sync", [
        'uptime_checks' => [],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    $response->assertStatus(403);
});

test('validates interval format', function () {
    $response = $this->actingAs($this->user)->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
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

test('validates url format', function () {
    $response = $this->actingAs($this->user)->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
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
    $response = $this->actingAs($this->user)->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
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

test('validates assertion types', function () {
    $response = $this->actingAs($this->user)->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
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
