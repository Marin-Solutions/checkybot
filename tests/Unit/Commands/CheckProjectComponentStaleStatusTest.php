<?php

use App\Models\ProjectComponent;
use App\Models\ProjectComponentHeartbeat;

test('component stale command is a no-op because component health is derived', function () {
    $component = ProjectComponent::factory()->create([
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subHour(),
        'is_stale' => false,
        'stale_detected_at' => null,
    ]);

    $this->artisan('project-components:check-stale')
        ->expectsOutput('Project component stale detection is disabled; component health is derived from active child checks.')
        ->assertSuccessful();

    $component->refresh();

    expect($component->current_status)->toBe('healthy')
        ->and($component->is_stale)->toBeFalse()
        ->and($component->stale_detected_at)->toBeNull()
        ->and(ProjectComponentHeartbeat::query()->count())->toBe(0);
});
