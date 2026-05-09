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

test('project component sync does not archive when full manifest is the string false', function () {
    $project = Project::factory()->create();

    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'worker-a',
        'source' => 'package',
        'created_by' => $project->created_by,
        'is_archived' => false,
    ]);

    $summary = app(ProjectComponentSyncService::class)->sync($project, [
        'full_manifest' => 'false',
        'declared_components' => [],
        'components' => [],
    ]);

    expect($summary['components']['archived'])->toBe(0);

    assertDatabaseHas('project_components', [
        'project_id' => $project->id,
        'name' => 'worker-a',
        'is_archived' => false,
    ]);
});

test('project component sync records stale heartbeats without regressing live state', function () {
    Mail::fake();

    $project = Project::factory()->create();

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $project->created_by,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
        ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'worker-stale',
        'source' => 'package',
        'created_by' => $project->created_by,
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
        'summary' => 'Worker is healthy now.',
        'metrics' => ['latency' => 12],
        'last_heartbeat_at' => '2026-03-21T12:05:00Z',
        'is_stale' => false,
        'stale_detected_at' => null,
    ]);

    $summary = app(ProjectComponentSyncService::class)->sync($project, [
        'components' => [
            [
                'name' => 'worker-stale',
                'status' => 'danger',
                'summary' => 'Delayed failure payload.',
                'interval' => '5m',
                'observed_at' => '2026-03-21T12:00:00Z',
                'metrics' => ['latency' => 2500],
            ],
        ],
    ]);

    $component->refresh();

    expect($summary['components']['updated'])->toBe(0)
        ->and($summary['heartbeats']['recorded'])->toBe(1)
        ->and($component->current_status)->toBe('healthy')
        ->and($component->last_reported_status)->toBe('healthy')
        ->and($component->summary)->toBe('Worker is healthy now.')
        ->and($component->metrics)->toBe(['latency' => 12])
        ->and($component->last_heartbeat_at?->toISOString())->toBe('2026-03-21T12:05:00.000000Z')
        ->and($component->is_stale)->toBeFalse()
        ->and($component->stale_detected_at)->toBeNull();

    assertDatabaseHas('project_component_heartbeats', [
        'project_component_id' => $component->id,
        'component_name' => 'worker-stale',
        'status' => 'danger',
        'event' => 'heartbeat',
        'summary' => 'Delayed failure payload.',
    ]);

    Mail::assertNothingSent();
});

test('project component sync applies only strictly newer heartbeats to live state', function () {
    $project = Project::factory()->create();

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'worker-order',
        'source' => 'package',
        'created_by' => $project->created_by,
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
        'summary' => 'Current heartbeat.',
        'metrics' => ['latency' => 10],
        'last_heartbeat_at' => '2026-03-21T12:05:00Z',
    ]);

    $summary = app(ProjectComponentSyncService::class)->sync($project, [
        'components' => [
            [
                'name' => 'worker-order',
                'status' => 'warning',
                'summary' => 'Same timestamp should not win.',
                'interval' => '5m',
                'observed_at' => '2026-03-21T12:05:00Z',
                'metrics' => ['latency' => 900],
            ],
            [
                'name' => 'worker-order',
                'status' => 'danger',
                'summary' => 'New failure should win.',
                'interval' => '5m',
                'observed_at' => '2026-03-21T12:06:00Z',
                'metrics' => ['latency' => 1500],
            ],
        ],
    ]);

    $component->refresh();

    expect($summary['components']['updated'])->toBe(1)
        ->and($summary['heartbeats']['recorded'])->toBe(2)
        ->and($component->current_status)->toBe('danger')
        ->and($component->last_reported_status)->toBe('danger')
        ->and($component->summary)->toBe('New failure should win.')
        ->and($component->metrics)->toBe(['latency' => 1500])
        ->and($component->last_heartbeat_at?->toISOString())->toBe('2026-03-21T12:06:00.000000Z');

    expect($component->heartbeats()->where('status', 'warning')->exists())->toBeTrue();
    expect($component->heartbeats()->where('status', 'danger')->exists())->toBeTrue();
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

    $setting = NotificationSetting::factory()
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

    $setting->refresh();

    expect($setting->last_delivery_kind)->toBe('send');
    expect($setting->last_delivery_succeeded)->toBeTrue();
    expect($setting->last_delivery_response_code)->toBeNull();
    expect($setting->last_delivery_summary)->toBe('Email accepted by configured mail transport.');
    expect($setting->last_delivery_attempted_at)->not->toBeNull();
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

    $failedSetting = NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $project->created_by,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
            'address' => 'failed@example.com',
        ]);
    $successfulSetting = NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $project->created_by,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
            'address' => 'delivered@example.com',
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

    $failedSetting->refresh();
    $successfulSetting->refresh();

    expect($failedSetting->last_delivery_kind)->toBe('send');
    expect($failedSetting->last_delivery_succeeded)->toBeFalse();
    expect($failedSetting->last_delivery_response_code)->toBeNull();
    expect($failedSetting->last_delivery_summary)->toContain('Mail transport error: SMTP down');
    expect($failedSetting->last_delivery_attempted_at)->not->toBeNull();
    expect($successfulSetting->last_delivery_kind)->toBe('send');
    expect($successfulSetting->last_delivery_succeeded)->toBeTrue();
    expect($successfulSetting->last_delivery_summary)->toBe('Email accepted by configured mail transport.');
    expect($successfulSetting->last_delivery_attempted_at)->not->toBeNull();
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
