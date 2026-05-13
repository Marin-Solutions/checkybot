<?php

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Mail\ProjectComponentAlertMail;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProjectComponentHeartbeat;
use App\Models\User;
use App\Services\ProjectComponentNotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

test('project component notification service includes component scoped channels and matching global channels', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $user = User::factory()->create();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'name' => 'queue',
        'summary' => 'Queue depth is above threshold.',
    ]);
    $otherComponent = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    $componentChannel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://hooks.example.test/component',
    ]);
    $globalChannel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://hooks.example.test/global',
    ]);
    $otherComponentChannel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://hooks.example.test/other-component',
    ]);

    NotificationSetting::factory()->create([
        'user_id' => $user->id,
        'project_component_id' => $component->id,
        'scope' => NotificationScopesEnum::PROJECT_COMPONENT,
        'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
        'channel_type' => NotificationChannelTypesEnum::WEBHOOK,
        'notification_channel_id' => $componentChannel->id,
        'address' => null,
        'flag_active' => true,
    ]);
    NotificationSetting::factory()->create([
        'user_id' => $user->id,
        'scope' => NotificationScopesEnum::GLOBAL,
        'inspection' => WebsiteServicesEnum::ALL_CHECK,
        'channel_type' => NotificationChannelTypesEnum::WEBHOOK,
        'notification_channel_id' => $globalChannel->id,
        'address' => null,
        'flag_active' => true,
    ]);
    NotificationSetting::factory()->create([
        'user_id' => $user->id,
        'project_component_id' => $otherComponent->id,
        'scope' => NotificationScopesEnum::PROJECT_COMPONENT,
        'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
        'channel_type' => NotificationChannelTypesEnum::WEBHOOK,
        'notification_channel_id' => $otherComponentChannel->id,
        'address' => null,
        'flag_active' => true,
    ]);

    $delivered = app(ProjectComponentNotificationService::class)
        ->notify($component, 'heartbeat', 'danger');

    expect($delivered)->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.example.test/component');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.example.test/global');
    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://hooks.example.test/other-component');
});

test('project component notification service fails gracefully when project relationship is missing', function () {
    Http::fake();

    $component = ProjectComponent::factory()->create([
        'name' => 'orphaned-worker',
    ]);
    $component->setRelation('project', null);

    $delivered = app(ProjectComponentNotificationService::class)
        ->notify($component, 'heartbeat', 'danger');

    expect($delivered)->toBeFalse();

    Http::assertNothingSent();
});

test('project component notification service includes observed evidence in webhook payload', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    config(['monitor.project_component_stale_grace_minutes' => 2]);

    $user = User::factory()->create();
    $project = Project::factory()->create([
        'created_by' => $user->id,
        'name' => 'Billing',
    ]);
    $observedAt = now()->setTime(12, 30);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'name' => 'queue',
        'summary' => 'Queue depth is above threshold.',
        'declared_interval' => '5m',
        'interval_minutes' => 5,
        'current_status' => 'danger',
        'last_heartbeat_at' => $observedAt,
        'metrics' => ['queue_depth' => 21],
    ]);
    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'queue',
        'status' => 'danger',
        'summary' => 'Queue depth is above threshold.',
        'metrics' => ['queue_depth' => 42, 'oldest_job_seconds' => 91],
        'observed_at' => $observedAt,
    ]);

    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://hooks.example.test/component',
        'request_body' => [
            'message' => '{message}',
            'description' => '{description}',
        ],
    ]);

    NotificationSetting::factory()->create([
        'user_id' => $user->id,
        'project_component_id' => $component->id,
        'scope' => NotificationScopesEnum::PROJECT_COMPONENT,
        'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
        'channel_type' => NotificationChannelTypesEnum::WEBHOOK,
        'notification_channel_id' => $channel->id,
        'address' => null,
        'flag_active' => true,
    ]);

    $delivered = app(ProjectComponentNotificationService::class)
        ->notify($component, 'heartbeat', 'danger');

    expect($delivered)->toBeTrue();

    Http::assertSent(function ($request) use ($observedAt): bool {
        $body = $request->data();
        $description = $body['description'] ?? '';

        return $request->url() === 'https://hooks.example.test/component'
            && str_contains($body['message'] ?? '', 'Billing / queue reported danger.')
            && str_contains($description, 'Observed at: '.$observedAt->toIso8601String())
            && str_contains($description, 'Interval: 5m (5 min)')
            && str_contains($description, 'Stale threshold: '.$observedAt->copy()->addMinutes(7)->toIso8601String())
            && str_contains($description, 'Delivery state: Receiving heartbeats')
            && str_contains($description, '"queue_depth": 42')
            && str_contains($description, '"oldest_job_seconds": 91');
    });
});

test('project component notification service includes observed evidence in mail payload', function () {
    Mail::fake();

    config(['monitor.project_component_stale_grace_minutes' => 3]);

    $user = User::factory()->create();
    $project = Project::factory()->create([
        'created_by' => $user->id,
        'name' => 'Checkout',
    ]);
    $observedAt = now()->setTime(9, 15);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'name' => 'payments',
        'summary' => 'Payment provider latency is elevated.',
        'declared_interval' => '10m',
        'interval_minutes' => 10,
        'current_status' => 'warning',
        'last_heartbeat_at' => $observedAt,
        'metrics' => ['latency_ms' => 1250],
    ]);
    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $component->id,
        'component_name' => 'payments',
        'status' => 'warning',
        'metrics' => ['latency_ms' => 1250, 'error_rate' => 0.04],
        'observed_at' => $observedAt,
    ]);

    NotificationSetting::factory()
        ->projectComponentScope()
        ->email()
        ->create([
            'user_id' => $user->id,
            'project_component_id' => $component->id,
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH,
            'address' => 'alerts@example.test',
        ]);

    $delivered = app(ProjectComponentNotificationService::class)
        ->notify($component, 'heartbeat', 'warning');

    expect($delivered)->toBeTrue();

    Mail::assertSent(ProjectComponentAlertMail::class, function (ProjectComponentAlertMail $mail) use ($observedAt): bool {
        return $mail->payload['project_name'] === 'Checkout'
            && $mail->payload['component_name'] === 'payments'
            && $mail->payload['observed_at'] === $observedAt->toIso8601String()
            && $mail->payload['interval_formatted'] === '10m (10 min)'
            && $mail->payload['stale_threshold_at'] === $observedAt->copy()->addMinutes(13)->toIso8601String()
            && $mail->payload['delivery_state_label'] === 'Receiving heartbeats'
            && $mail->payload['metrics'] === ['latency_ms' => 1250, 'error_rate' => 0.04]
            && str_contains($mail->payload['formatted_metrics'], '"latency_ms": 1250')
            && str_contains($mail->payload['details'], 'Evidence:');
    });
});
