<?php

use App\Enums\WebsiteServicesEnum;
use App\Models\ApiKey;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);
    $this->project = Project::factory()->create(['created_by' => $this->user->id]);
});

test('sync stores package component state and appends heartbeat history', function () {
    $payload = [
        'components' => [
            [
                'name' => 'database',
                'interval' => '5m',
                'status' => 'healthy',
                'summary' => 'Primary database is healthy',
                'metrics' => [
                    'connections' => 12,
                    'reachable' => true,
                ],
                'observed_at' => '2026-03-21T12:00:00Z',
            ],
            [
                'name' => 'queue',
                'interval' => '1m',
                'status' => 'warning',
                'summary' => 'Queue backlog is rising',
                'metrics' => [
                    'pending_jobs' => 144,
                ],
                'observed_at' => '2026-03-21T12:00:00Z',
            ],
        ],
    ];

    $response = $this->withToken($this->apiKey->key)->postJson(
        "/api/v1/projects/{$this->project->id}/components/sync",
        $payload
    );

    $response->assertOk()
        ->assertJsonPath('summary.components.created', 2)
        ->assertJsonPath('summary.components.updated', 0)
        ->assertJsonPath('summary.components.archived', 0)
        ->assertJsonPath('summary.heartbeats.recorded', 2);

    $this->assertDatabaseHas('project_components', [
        'project_id' => $this->project->id,
        'name' => 'database',
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
        'interval_minutes' => 5,
        'archived_at' => null,
    ]);

    $this->assertDatabaseHas('project_component_heartbeats', [
        'component_name' => 'queue',
        'status' => 'warning',
        'event' => 'heartbeat',
    ]);

    $secondResponse = $this->withToken($this->apiKey->key)->postJson(
        "/api/v1/projects/{$this->project->id}/components/sync",
        [
            'components' => [
                [
                    'name' => 'database',
                    'interval' => '5m',
                    'status' => 'danger',
                    'summary' => 'Primary database is degraded',
                    'metrics' => [
                        'connections' => 310,
                    ],
                    'observed_at' => '2026-03-21T12:05:00Z',
                ],
            ],
        ]
    );

    $secondResponse->assertOk()
        ->assertJsonPath('summary.components.created', 0)
        ->assertJsonPath('summary.components.updated', 1)
        ->assertJsonPath('summary.components.archived', 1)
        ->assertJsonPath('summary.heartbeats.recorded', 1);

    expect(
        \App\Models\ProjectComponentHeartbeat::query()
            ->where('component_name', 'database')
            ->count()
    )->toBe(2);

    $this->assertDatabaseHas('project_components', [
        'project_id' => $this->project->id,
        'name' => 'queue',
        'is_archived' => true,
    ]);
});

test('warning and danger component events use existing notification settings', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    NotificationSetting::factory()->webhook()->create([
        'user_id' => $this->user->id,
        'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
    ]);

    $this->withToken($this->apiKey->key)->postJson(
        "/api/v1/projects/{$this->project->id}/components/sync",
        [
            'components' => [
                [
                    'name' => 'cache',
                    'interval' => '5m',
                    'status' => 'warning',
                    'summary' => 'Redis memory usage is elevated',
                    'metrics' => [
                        'memory_percent' => 82,
                    ],
                    'observed_at' => '2026-03-21T12:00:00Z',
                ],
            ],
        ]
    )->assertOk();

    Http::assertSentCount(1);
});
