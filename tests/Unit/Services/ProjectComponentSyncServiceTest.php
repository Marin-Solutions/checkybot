<?php

use App\Models\Project;
use App\Models\ProjectComponent;
use App\Services\ProjectComponentSyncService;

test('project component sync creates and updates declarations only', function () {
    $project = Project::factory()->create();

    $summary = app(ProjectComponentSyncService::class)->sync($project, [
        'full_manifest' => true,
        'declared_components' => [
            ['name' => 'queue', 'interval' => '5m'],
        ],
    ]);

    expect($summary)->toBe([
        'components' => [
            'created' => 1,
            'updated' => 0,
            'archived' => 0,
        ],
    ]);

    $component = ProjectComponent::query()->where('name', 'queue')->sole();

    expect($component->declared_interval)->toBe('5m')
        ->and($component->current_status)->toBe('unknown')
        ->and($component->last_heartbeat_at)->toBeNull()
        ->and($component->is_stale)->toBeFalse();

    $summary = app(ProjectComponentSyncService::class)->sync($project, [
        'full_manifest' => true,
        'declared_components' => [
            ['name' => 'queue', 'interval' => '10m'],
        ],
    ]);

    expect($summary['components'])->toBe([
        'created' => 0,
        'updated' => 1,
        'archived' => 0,
    ])
        ->and($component->fresh()->interval_minutes)->toBe(10);
});

test('project component sync archives missing package components on full manifests', function () {
    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'is_archived' => false,
    ]);

    $summary = app(ProjectComponentSyncService::class)->sync($project, [
        'full_manifest' => true,
        'declared_components' => [],
    ]);

    expect($summary['components']['archived'])->toBe(1)
        ->and($component->fresh()->is_archived)->toBeTrue()
        ->and($component->fresh()->last_heartbeat_at)->toBeNull()
        ->and($component->fresh()->is_stale)->toBeFalse();
});
