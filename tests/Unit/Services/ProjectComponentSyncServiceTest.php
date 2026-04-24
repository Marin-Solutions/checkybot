<?php

use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Services\ProjectComponentSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

test('project component sync preloads known components and avoids per-item name lookups', function () {
    $project = Project::factory()->create();

    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'worker-a',
        'source' => 'package',
        'created_by' => $project->created_by,
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
    ]);

    $payload = [
        'declared_components' => [
            ['name' => 'worker-a', 'interval' => '5m'],
            ['name' => 'worker-b', 'interval' => '10m'],
        ],
        'components' => [
            [
                'name' => 'worker-a',
                'status' => 'healthy',
                'summary' => 'ok',
                'interval' => '5m',
                'observed_at' => now()->toDateTimeString(),
                'metrics' => ['latency' => 12],
            ],
            [
                'name' => 'worker-b',
                'status' => 'healthy',
                'summary' => 'ok',
                'interval' => '10m',
                'observed_at' => now()->toDateTimeString(),
                'metrics' => ['latency' => 20],
            ],
        ],
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();

    $summary = app(ProjectComponentSyncService::class)->sync($project, $payload);

    $queries = collect(DB::getQueryLog())->pluck('query');
    $perItemNameLookups = $queries->filter(
        fn (string $query) => preg_match('/from\s+["`]?project_components["`]?\s+where\s+.*["`]?name["`]?\s*=\s*\?/i', $query) === 1
    );

    expect($summary['components']['created'])->toBe(1);
    expect($summary['heartbeats']['recorded'])->toBe(2);
    expect($perItemNameLookups)->toHaveCount(0);

    assertDatabaseHas('project_components', [
        'project_id' => $project->id,
        'name' => 'worker-b',
    ]);

    assertDatabaseHas('project_component_heartbeats', [
        'component_name' => 'worker-a',
        'event' => 'heartbeat',
    ]);
});

test('project component sync sends recovery notifications when a component returns to healthy', function () {
    Mail::fake();

    $project = Project::factory()->create();

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'worker-a',
        'source' => 'package',
        'created_by' => $project->created_by,
        'current_status' => 'danger',
        'last_reported_status' => 'danger',
        'is_stale' => true,
        'stale_detected_at' => now()->subMinutes(5),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $project->created_by,
            'inspection' => \App\Enums\WebsiteServicesEnum::APPLICATION_HEALTH,
        ]);

    app(ProjectComponentSyncService::class)->sync($project, [
        'declared_components' => [
            ['name' => 'worker-a', 'interval' => '5m'],
        ],
        'components' => [
            [
                'name' => 'worker-a',
                'status' => 'healthy',
                'summary' => 'Queue worker is processing normally again.',
                'interval' => '5m',
                'observed_at' => now()->toDateTimeString(),
                'metrics' => ['latency' => 12],
            ],
        ],
    ]);

    $component->refresh();

    expect($component->current_status)->toBe('healthy')
        ->and($component->is_stale)->toBeFalse()
        ->and($component->stale_detected_at)->toBeNull();

    Mail::assertSent(\App\Mail\ProjectComponentAlertMail::class, function (\App\Mail\ProjectComponentAlertMail $mail): bool {
        return ($mail->payload['subject'] ?? null) === 'Application component recovered: worker-a'
            && ($mail->payload['title'] ?? null) === 'Application component recovered';
    });
});
