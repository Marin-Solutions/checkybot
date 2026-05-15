<?php

use App\Models\ApiKey;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use App\Models\Website;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);
    $this->project = Project::factory()->create(['created_by' => $this->user->id]);
});

test('sync stores package component declarations without heartbeat history', function () {
    $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/components/sync", [
            'full_manifest' => true,
            'declared_components' => [
                ['name' => 'database', 'interval' => '5m'],
                ['name' => 'queue', 'interval' => '1m'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('summary.components.created', 2)
        ->assertJsonPath('summary.components.updated', 0)
        ->assertJsonPath('summary.components.archived', 0)
        ->assertJsonMissingPath('summary.heartbeats');

    $database = ProjectComponent::query()
        ->where('project_id', $this->project->id)
        ->where('name', 'database')
        ->sole();

    expect($database->current_status)->toBe('unknown')
        ->and($database->last_reported_status)->toBe('unknown')
        ->and($database->last_heartbeat_at)->toBeNull()
        ->and($database->is_stale)->toBeFalse()
        ->and($database->derivedCurrentStatus())->toBe('pending');
});

test('sync rejects runtime component heartbeat observations', function () {
    $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/components/sync", [
            'declared_components' => [
                ['name' => 'queue', 'interval' => '5m'],
            ],
            'components' => [
                [
                    'name' => 'queue',
                    'interval' => '5m',
                    'status' => 'danger',
                    'summary' => 'Queue backlog is rising.',
                    'metrics' => ['pending_jobs' => 100],
                    'observed_at' => now()->toISOString(),
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components']);

    expect(ProjectComponent::query()->count())->toBe(0);
});

test('sync rejects runtime fields inside component declarations', function () {
    $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/components/sync", [
            'declared_components' => [
                [
                    'name' => 'queue',
                    'interval' => '5m',
                    'status' => 'warning',
                    'summary' => 'Queue backlog is rising.',
                    'metrics' => ['pending_jobs' => 100],
                    'observed_at' => now()->toISOString(),
                    'last_heartbeat_at' => now()->toISOString(),
                    'stale_at' => now()->addMinutes(5)->toISOString(),
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'declared_components.0.status',
            'declared_components.0.summary',
            'declared_components.0.metrics',
            'declared_components.0.observed_at',
            'declared_components.0.last_heartbeat_at',
            'declared_components.0.stale_at',
        ]);

    expect(ProjectComponent::query()->count())->toBe(0);
});

test('full manifest archives missing package components but preserves their child check metadata', function () {
    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'name' => 'queue',
        'is_archived' => false,
    ]);

    $api = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'project_component_id' => $component->id,
        'source' => 'package',
        'is_enabled' => true,
        'current_status' => 'danger',
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/components/sync", [
            'full_manifest' => true,
            'declared_components' => [],
        ])
        ->assertOk()
        ->assertJsonPath('summary.components.archived', 1);

    $component->refresh();

    expect($component->is_archived)->toBeTrue()
        ->and($api->fresh()->project_component_id)->toBe($component->id);
});

test('component status rolls up worst active child check and ignores disabled or archived children', function () {
    $component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'current_status' => 'healthy',
        'last_heartbeat_at' => now(),
        'is_stale' => true,
    ]);

    expect($component->derivedCurrentStatus())->toBe('pending');

    MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'project_component_id' => $component->id,
        'is_enabled' => true,
        'current_status' => 'healthy',
    ]);

    Website::factory()->create([
        'project_id' => $this->project->id,
        'project_component_id' => $component->id,
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'warning',
    ]);

    MonitorApis::factory()->disabled()->create([
        'project_id' => $this->project->id,
        'project_component_id' => $component->id,
        'current_status' => 'danger',
    ]);

    $archivedWebsite = Website::factory()->create([
        'project_id' => $this->project->id,
        'project_component_id' => $component->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'current_status' => 'danger',
    ]);
    $archivedWebsite->delete();

    expect($component->fresh()->derivedCurrentStatus())->toBe('warning');
});
