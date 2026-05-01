<?php

use App\Enums\WebsiteServicesEnum;
use App\Models\ApiKey;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);
    $this->project = Project::factory()->create(['created_by' => $this->user->id]);
});

test('sync stores package component state and appends heartbeat history', function () {
    $payload = [
        'declared_components' => [
            [
                'name' => 'database',
                'interval' => '5m',
            ],
            [
                'name' => 'queue',
                'interval' => '1m',
            ],
        ],
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
            'declared_components' => [
                [
                    'name' => 'database',
                    'interval' => '5m',
                ],
                [
                    'name' => 'queue',
                    'interval' => '1m',
                ],
            ],
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
        ->assertJsonPath('summary.components.archived', 0)
        ->assertJsonPath('summary.heartbeats.recorded', 1);

    expect(
        \App\Models\ProjectComponentHeartbeat::query()
            ->where('component_name', 'database')
            ->count()
    )->toBe(2);

    $this->assertDatabaseHas('project_components', [
        'project_id' => $this->project->id,
        'name' => 'queue',
        'is_archived' => false,
    ]);

    $thirdResponse = $this->withToken($this->apiKey->key)->postJson(
        "/api/v1/projects/{$this->project->id}/components/sync",
        [
            'declared_components' => [
                [
                    'name' => 'database',
                    'interval' => '5m',
                ],
            ],
            'components' => [
                [
                    'name' => 'database',
                    'interval' => '5m',
                    'status' => 'healthy',
                    'summary' => 'Primary database recovered',
                    'metrics' => [
                        'connections' => 12,
                    ],
                    'observed_at' => '2026-03-21T12:10:00Z',
                ],
            ],
        ]
    );

    $thirdResponse->assertOk()
        ->assertJsonPath('summary.components.created', 0)
        ->assertJsonPath('summary.components.updated', 1)
        ->assertJsonPath('summary.components.archived', 1)
        ->assertJsonPath('summary.heartbeats.recorded', 1);

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
            'declared_components' => [
                [
                    'name' => 'cache',
                    'interval' => '5m',
                ],
            ],
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

test('sync does not archive active components reported only by heartbeat payload', function () {
    ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'queue',
        'source' => 'package',
        'is_archived' => false,
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'cache',
        'source' => 'package',
        'is_archived' => false,
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'old-cron',
        'source' => 'package',
        'is_archived' => false,
    ]);

    $response = $this->withToken($this->apiKey->key)->postJson(
        "/api/v1/projects/{$this->project->id}/components/sync",
        [
            'declared_components' => [
                [
                    'name' => 'cache',
                    'interval' => '5m',
                ],
            ],
            'components' => [
                [
                    'name' => 'queue',
                    'interval' => '5m',
                    'status' => 'healthy',
                    'summary' => 'Queue workers are running',
                    'observed_at' => '2026-03-21T12:00:00Z',
                ],
            ],
        ]
    );

    $response->assertOk()
        ->assertJsonPath('summary.components.archived', 1)
        ->assertJsonPath('summary.heartbeats.recorded', 1);

    $this->assertDatabaseHas('project_components', [
        'project_id' => $this->project->id,
        'name' => 'queue',
        'is_archived' => false,
    ]);

    $this->assertDatabaseHas('project_components', [
        'project_id' => $this->project->id,
        'name' => 'cache',
        'is_archived' => false,
    ]);

    $this->assertDatabaseHas('project_components', [
        'project_id' => $this->project->id,
        'name' => 'old-cron',
        'is_archived' => true,
    ]);
});

test('component sync persists heartbeat state when webhook notification fails', function () {
    Log::spy();

    Http::fake(function () {
        throw new RuntimeException('Webhook transport unavailable');
    });

    NotificationSetting::factory()->webhook()->create([
        'user_id' => $this->user->id,
        'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
    ]);

    $response = $this->withToken($this->apiKey->key)->postJson(
        "/api/v1/projects/{$this->project->id}/components/sync",
        [
            'declared_components' => [
                [
                    'name' => 'queue',
                    'interval' => '5m',
                ],
            ],
            'components' => [
                [
                    'name' => 'queue',
                    'interval' => '5m',
                    'status' => 'danger',
                    'summary' => 'Queue workers are not processing jobs',
                    'metrics' => [
                        'pending_jobs' => 544,
                    ],
                    'observed_at' => '2026-03-21T12:00:00Z',
                ],
            ],
        ]
    );

    $response->assertOk()
        ->assertJsonPath('summary.heartbeats.recorded', 1);

    $component = ProjectComponent::query()
        ->where('project_id', $this->project->id)
        ->where('name', 'queue')
        ->firstOrFail();

    expect($component->current_status)->toBe('danger');

    $this->assertDatabaseHas('project_component_heartbeats', [
        'project_component_id' => $component->id,
        'status' => 'danger',
        'event' => 'heartbeat',
    ]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'Failed to deliver project component notification webhook'));
});

test('component sync logs non-2xx webhook responses as failed notification delivery', function () {
    Log::spy();

    Http::fake([
        '*' => Http::response(['error' => 'rate limited'], 429),
    ]);

    NotificationSetting::factory()->webhook()->create([
        'user_id' => $this->user->id,
        'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
    ]);

    $response = $this->withToken($this->apiKey->key)->postJson(
        "/api/v1/projects/{$this->project->id}/components/sync",
        [
            'declared_components' => [
                [
                    'name' => 'queue',
                    'interval' => '5m',
                ],
            ],
            'components' => [
                [
                    'name' => 'queue',
                    'interval' => '5m',
                    'status' => 'danger',
                    'summary' => 'Queue workers are not processing jobs',
                    'metrics' => [
                        'pending_jobs' => 544,
                    ],
                    'observed_at' => '2026-03-21T12:00:00Z',
                ],
            ],
        ]
    );

    $response->assertOk()
        ->assertJsonPath('summary.heartbeats.recorded', 1);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Failed to deliver project component notification webhook')
            && ($context['response_code'] ?? null) === 429);
});

test('component sync rejects zero intervals at the request boundary', function () {
    $this->withToken($this->apiKey->key)->postJson(
        "/api/v1/projects/{$this->project->id}/components/sync",
        [
            'declared_components' => [
                [
                    'name' => 'database',
                    'interval' => '0m',
                ],
            ],
            'components' => [
                [
                    'name' => 'database',
                    'interval' => '0m',
                    'status' => 'healthy',
                    'summary' => 'Primary database is healthy',
                    'observed_at' => '2026-03-21T12:00:00Z',
                ],
            ],
        ]
    )->assertStatus(422)
        ->assertJsonValidationErrors([
            'declared_components.0.interval',
            'components.0.interval',
        ]);
});

test('component sync rejects future observed timestamps at the request boundary', function () {
    Carbon::setTestNow('2026-03-21 12:00:00');

    try {
        $this->withToken($this->apiKey->key)->postJson(
            "/api/v1/projects/{$this->project->id}/components/sync",
            [
                'declared_components' => [
                    [
                        'name' => 'database',
                        'interval' => '5m',
                    ],
                ],
                'components' => [
                    [
                        'name' => 'database',
                        'interval' => '5m',
                        'status' => 'healthy',
                        'summary' => 'Primary database is healthy',
                        'observed_at' => '2026-03-21T12:01:00Z',
                    ],
                ],
            ]
        )->assertStatus(422)
            ->assertJsonValidationErrors([
                'components.0.observed_at',
            ]);

        expect(ProjectComponent::query()->where('project_id', $this->project->id)->exists())->toBeFalse();
    } finally {
        Carbon::setTestNow();
    }
});
