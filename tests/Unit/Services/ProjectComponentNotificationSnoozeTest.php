<?php

use App\Enums\WebsiteServicesEnum;
use App\Mail\ProjectComponentAlertMail;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Services\ProjectComponentNotificationService;
use App\Services\ProjectComponentSyncService;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

test('project component notifications are skipped while silenced_until is in the future', function () {
    Mail::fake();

    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $project->created_by,
        'silenced_until' => now()->addHour(),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $project->created_by,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
        ]);

    $result = app(ProjectComponentNotificationService::class)->notify($component, 'stale', 'danger');

    expect($result)->toBeTrue();
    Mail::assertNothingSent();
});

test('project component notifications resume after silenced_until passes', function () {
    Mail::fake();

    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $project->created_by,
        'silenced_until' => now()->subMinute(),
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $project->created_by,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
        ]);

    $result = app(ProjectComponentNotificationService::class)->notify($component, 'stale', 'danger');

    expect($result)->toBeTrue();
    Mail::assertSent(ProjectComponentAlertMail::class);
});

test('project component notification service returns false when every channel attempt fails', function () {
    Log::spy();

    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $project->created_by,
        'silenced_until' => null,
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $project->created_by,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
        ]);

    $pendingMail = Mockery::mock(PendingMail::class);
    $pendingMail->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP down'));
    Mail::shouldReceive('to')->once()->andReturn($pendingMail);

    $result = app(ProjectComponentNotificationService::class)->notify($component, 'stale', 'danger');

    expect($result)->toBeFalse();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'Failed to deliver project component notification mail'));
});

test('project component notification service re-reads silenced_until before delivery', function () {
    Mail::fake();

    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $project->created_by,
        'silenced_until' => null,
    ]);

    NotificationSetting::factory()
        ->globalScope()
        ->email()
        ->create([
            'user_id' => $project->created_by,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
        ]);

    ProjectComponent::query()
        ->whereKey($component->id)
        ->update(['silenced_until' => now()->addHour()]);

    expect($component->silenced_until)->toBeNull();

    $result = app(ProjectComponentNotificationService::class)->notify($component, 'stale', 'danger');

    expect($result)->toBeTrue();
    Mail::assertNothingSent();
});

test('project component sync keeps recording heartbeats while notifications are snoozed', function () {
    Mail::fake();

    $project = Project::factory()->create();
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'queue-worker',
        'source' => 'package',
        'created_by' => $project->created_by,
        'current_status' => 'danger',
        'last_reported_status' => 'danger',
        'is_stale' => true,
        'stale_detected_at' => now()->subMinutes(5),
        'silenced_until' => now()->addHour(),
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
            ['name' => 'queue-worker', 'interval' => '5m'],
        ],
        'components' => [
            [
                'name' => 'queue-worker',
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
        ->and($component->stale_detected_at)->toBeNull()
        ->and($component->heartbeats()->where('event', 'heartbeat')->exists())->toBeTrue();

    Mail::assertNothingSent();
});
