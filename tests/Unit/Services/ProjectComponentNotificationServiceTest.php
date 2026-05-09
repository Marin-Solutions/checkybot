<?php

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use App\Services\ProjectComponentNotificationService;
use Illuminate\Support\Facades\Http;

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
