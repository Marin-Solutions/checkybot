<?php

namespace Tests\Feature\Api\V1;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Services\CheckSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectChecksSyncTest extends TestCase
{
    protected User $user;

    protected Project $project;

    protected CheckSyncService $syncService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->create(['created_by' => $this->user->id]);
        $this->syncService = app(CheckSyncService::class);
    }

    public function test_syncs_uptime_checks_successfully(): void
    {
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

        $this->assertEquals([
            'created' => 1,
            'updated' => 0,
            'deleted' => 0,
        ], $summary['uptime_checks']);

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
    }

    public function test_syncs_ssl_checks_successfully(): void
    {
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

        $this->assertEquals([
            'created' => 1,
            'updated' => 0,
            'deleted' => 0,
        ], $summary['ssl_checks']);

        $this->assertDatabaseHas('websites', [
            'project_id' => $this->project->id,
            'name' => 'homepage-ssl',
            'url' => 'https://ssl-example.com',
            'ssl_check' => true,
            'source' => 'package',
            'package_name' => 'homepage-ssl',
            'package_interval' => '1d',
        ]);
    }

    public function test_syncs_api_checks_with_assertions_successfully(): void
    {
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

        $this->assertEquals([
            'created' => 1,
            'updated' => 0,
            'deleted' => 0,
        ], $summary['api_checks']);

        $this->assertDatabaseHas('monitor_apis', [
            'project_id' => $this->project->id,
            'title' => 'health-check',
            'url' => 'https://api.example.com/health',
            'source' => 'package',
            'package_name' => 'health-check',
            'package_interval' => '5m',
        ]);

        $api = MonitorApis::where('package_name', 'health-check')->first();
        $this->assertCount(2, $api->assertions);
    }

    public function test_updates_existing_checks(): void
    {
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

        $this->assertEquals([
            'created' => 0,
            'updated' => 1,
            'deleted' => 0,
        ], $summary['uptime_checks']);

        $this->assertDatabaseHas('websites', [
            'package_name' => 'homepage-uptime',
            'url' => 'https://new-url.com',
            'uptime_interval' => 10,
        ]);
    }

    public function test_prunes_orphaned_checks(): void
    {
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

        $this->assertEquals([
            'created' => 1,
            'updated' => 0,
            'deleted' => 1,
        ], $summary['uptime_checks']);

        $this->assertDatabaseMissing('websites', ['package_name' => 'old-check']);
        $this->assertDatabaseHas('websites', ['package_name' => 'new-check']);
    }

    public function test_preserves_manual_checks_during_sync(): void
    {
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
    }

    public function test_requires_authentication(): void
    {
        $response = $this->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [],
        ]);

        $response->assertStatus(401);
    }

    public function test_requires_project_ownership(): void
    {
        $otherUser = User::factory()->create();
        $otherProject = Project::factory()->create(['created_by' => $otherUser->id]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/projects/{$otherProject->id}/checks/sync", [
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => [],
        ]);

        $response->assertStatus(403);
    }

    public function test_validates_interval_format(): void
    {
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
    }

    public function test_validates_url_format(): void
    {
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
    }

    public function test_validates_required_fields(): void
    {
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
    }

    public function test_validates_assertion_types(): void
    {
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
    }

    public function test_syncs_multiple_check_types_atomically(): void
    {
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
    }

    public function test_replaces_assertions_on_api_check_update(): void
    {
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
        $this->assertCount(1, $api->assertions);
        $this->assertEquals('new_field', $api->assertions->first()->data_path);
    }
}
