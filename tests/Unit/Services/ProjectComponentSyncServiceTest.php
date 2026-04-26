<?php

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Services\ProjectComponentSyncService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
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

test('project component sync sends recovery notifications when a stale-only component returns to healthy', function () {
    Mail::fake();

    $project = Project::factory()->create();

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'worker-b',
        'source' => 'package',
        'created_by' => $project->created_by,
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
        'is_stale' => true,
        'stale_detected_at' => now()->subMinutes(5),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $project->created_by,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
        ]);

    app(ProjectComponentSyncService::class)->sync($project, [
        'declared_components' => [
            ['name' => 'worker-b', 'interval' => '5m'],
        ],
        'components' => [
            [
                'name' => 'worker-b',
                'status' => 'healthy',
                'summary' => 'Queue worker heartbeat resumed.',
                'interval' => '5m',
                'observed_at' => now()->toDateTimeString(),
                'metrics' => ['latency' => 10],
            ],
        ],
    ]);

    $component->refresh();

    expect($component->is_stale)->toBeFalse()
        ->and($component->stale_detected_at)->toBeNull();

    Mail::assertSent(\App\Mail\ProjectComponentAlertMail::class, function (\App\Mail\ProjectComponentAlertMail $mail): bool {
        return ($mail->payload['subject'] ?? null) === 'Application component recovered: worker-b';
    });
});

test('project component sync continues after a mail notification channel fails', function () {
    Log::spy();

    $project = Project::factory()->create();

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'worker-c',
        'source' => 'package',
        'created_by' => $project->created_by,
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->count(2)
        ->create([
            'user_id' => $project->created_by,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
        ]);

    $failingMail = Mockery::mock(PendingMail::class);
    $failingMail->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));

    $successfulMail = Mockery::mock(PendingMail::class);
    $successfulMail->shouldReceive('send')->once();

    Mail::shouldReceive('to')
        ->twice()
        ->andReturn($failingMail, $successfulMail);

    app(ProjectComponentSyncService::class)->sync($project, [
        'declared_components' => [
            ['name' => 'worker-c', 'interval' => '5m'],
        ],
        'components' => [
            [
                'name' => 'worker-c',
                'status' => 'warning',
                'summary' => 'Queue worker latency is elevated.',
                'interval' => '5m',
                'observed_at' => now()->toDateTimeString(),
                'metrics' => ['latency' => 1200],
            ],
        ],
    ]);

    $component->refresh();

    expect($component->current_status)->toBe('warning')
        ->and($component->last_reported_status)->toBe('warning');

    assertDatabaseHas('project_component_heartbeats', [
        'project_component_id' => $component->id,
        'status' => 'warning',
        'event' => 'heartbeat',
    ]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'Failed to deliver project component notification mail'));
});

test('project component sync logs and continues when webhook notification channel is missing', function () {
    Log::spy();

    $project = Project::factory()->create();

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'worker-d',
        'source' => 'package',
        'created_by' => $project->created_by,
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->create([
            'user_id' => $project->created_by,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
            'channel_type' => NotificationChannelTypesEnum::WEBHOOK,
            'notification_channel_id' => null,
            'address' => null,
        ]);

    app(ProjectComponentSyncService::class)->sync($project, [
        'declared_components' => [
            ['name' => 'worker-d', 'interval' => '5m'],
        ],
        'components' => [
            [
                'name' => 'worker-d',
                'status' => 'danger',
                'summary' => 'Queue worker heartbeat failed.',
                'interval' => '5m',
                'observed_at' => now()->toDateTimeString(),
                'metrics' => ['latency' => 2500],
            ],
        ],
    ]);

    $component->refresh();

    expect($component->current_status)->toBe('danger')
        ->and($component->last_reported_status)->toBe('danger');

    assertDatabaseHas('project_component_heartbeats', [
        'project_component_id' => $component->id,
        'status' => 'danger',
        'event' => 'heartbeat',
    ]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'No channel found for project component notification setting'));
});
