<?php

use App\Enums\WebsiteServicesEnum;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('stale detection marks overdue components as stale danger and records history once', function () {
    config(['monitor.project_component_stale_grace_minutes' => 1]);

    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $user = User::factory()->create();
    $project = Project::factory()->create(['created_by' => $user->id]);

    NotificationSetting::factory()->webhook()->create([
        'user_id' => $user->id,
        'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'queue',
        'interval_minutes' => 5,
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(11),
        'stale_detected_at' => null,
    ]);

    $this->artisan('project-components:check-stale')
        ->assertSuccessful();

    $component->refresh();

    expect($component->current_status)->toBe('danger')
        ->and($component->is_stale)->toBeTrue()
        ->and($component->stale_detected_at)->not->toBeNull();

    $this->assertDatabaseHas('project_component_heartbeats', [
        'project_component_id' => $component->id,
        'status' => 'danger',
        'event' => 'stale',
    ]);

    $this->artisan('project-components:check-stale')
        ->assertSuccessful();

    expect(
        \App\Models\ProjectComponentHeartbeat::query()
            ->where('project_component_id', $component->id)
            ->where('event', 'stale')
            ->count()
    )->toBe(1);

    Http::assertSentCount(1);
});

test('stale detection waits for configured grace window after declared interval', function () {
    config(['monitor.project_component_stale_grace_minutes' => 2]);

    $project = Project::factory()->create();

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'scheduler',
        'interval_minutes' => 5,
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(6),
        'stale_detected_at' => null,
    ]);

    $this->artisan('project-components:check-stale')
        ->assertSuccessful();

    $component->refresh();

    expect($component->current_status)->toBe('healthy')
        ->and($component->is_stale)->toBeFalse()
        ->and($component->stale_detected_at)->toBeNull();

    $component->forceFill([
        'last_heartbeat_at' => now()->subMinutes(7),
    ])->save();

    $this->artisan('project-components:check-stale')
        ->assertSuccessful();

    $component->refresh();

    expect($component->current_status)->toBe('danger')
        ->and($component->is_stale)->toBeTrue()
        ->and($component->stale_detected_at)->not->toBeNull();
});

test('stale detection marks never reported components stale after creation interval plus grace', function () {
    config(['monitor.project_component_stale_grace_minutes' => 2]);

    $project = Project::factory()->create();

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'first-heartbeat-missing',
        'interval_minutes' => 5,
        'current_status' => 'unknown',
        'last_reported_status' => 'unknown',
        'last_heartbeat_at' => null,
        'created_at' => now()->subMinutes(6),
        'stale_detected_at' => null,
    ]);

    $this->artisan('project-components:check-stale')
        ->assertSuccessful();

    $component->refresh();

    expect($component->current_status)->toBe('unknown')
        ->and($component->is_stale)->toBeFalse()
        ->and($component->stale_detected_at)->toBeNull();

    $component->forceFill([
        'created_at' => now()->subMinutes(7),
    ])->save();

    $this->artisan('project-components:check-stale')
        ->assertSuccessful();

    $component->refresh();

    expect($component->current_status)->toBe('danger')
        ->and($component->summary)->toBe('Heartbeat expired')
        ->and($component->is_stale)->toBeTrue()
        ->and($component->stale_detected_at)->not->toBeNull();

    $this->assertDatabaseHas('project_component_heartbeats', [
        'project_component_id' => $component->id,
        'status' => 'danger',
        'event' => 'stale',
    ]);
});
