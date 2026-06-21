<?php

use App\Models\ApiKey;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use App\Models\Website;

test('component sync accepts declarations only and rejects heartbeat observations', function () {
    $user = User::factory()->create();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);
    $project = Project::factory()->create(['created_by' => $user->id]);

    $this->withToken($apiKey->key)
        ->postJson("/api/v1/projects/{$project->id}/components/sync", [
            'full_manifest' => true,
            'declared_components' => [
                ['name' => 'queue', 'interval' => '5m'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('summary.components.created', 1)
        ->assertJsonMissingPath('summary.heartbeats');

    $this->assertDatabaseHas('project_components', [
        'project_id' => $project->id,
        'name' => 'queue',
        'current_status' => 'unknown',
        'last_reported_status' => 'unknown',
    ]);

    $this->withToken($apiKey->key)
        ->postJson("/api/v1/projects/{$project->id}/components/sync", [
            'declared_components' => [
                ['name' => 'queue', 'interval' => '5m'],
            ],
            'components' => [
                [
                    'name' => 'queue',
                    'interval' => '5m',
                    'status' => 'danger',
                    'observed_at' => now()->toISOString(),
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components']);
});

test('component live status is derived from active child checks', function () {
    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'current_status' => 'healthy',
    ]);

    expect($component->derivedCurrentStatus())->toBe('pending');

    MonitorApis::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'is_enabled' => true,
        'current_status' => 'healthy',
    ]);

    Website::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'warning',
    ]);

    MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'current_status' => 'danger',
    ]);

    $archivedWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'current_status' => 'danger',
    ]);
    $archivedWebsite->delete();

    expect($component->fresh()->derivedCurrentStatus())->toBe('warning');
});
