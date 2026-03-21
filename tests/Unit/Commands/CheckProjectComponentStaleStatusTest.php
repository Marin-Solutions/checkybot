<?php

use App\Enums\WebsiteServicesEnum;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('stale detection marks overdue components as stale danger and records history once', function () {
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
