<?php

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Filament\Resources\MonitorApisResource\Pages\EditMonitorApis;
use App\Filament\Resources\ProjectComponents\Pages\CreateProjectComponent;
use App\Filament\Resources\ProjectComponents\Pages\EditProjectComponent;
use App\Filament\Resources\ProjectComponents\Pages\ListProjectComponents;
use App\Filament\Resources\ProjectComponents\Pages\ViewProjectComponent;
use App\Filament\Resources\ProjectComponents\ProjectComponentResource;
use App\Filament\Resources\ProjectComponents\RelationManagers\NotificationSettingsRelationManager;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\RelationManagers\ComponentsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\NotificationSettingsRelationManager as ProjectNotificationSettingsRelationManager;
use App\Filament\Resources\Projects\RelationManagers\PackageManagedApisRelationManager;
use App\Filament\Resources\Projects\RelationManagers\PackageManagedWebsitesRelationManager;
use App\Filament\Resources\WebsiteResource\Pages\EditWebsite;
use App\Jobs\LogUptimeSslJob;
use App\Jobs\RunApiMonitorDiagnosticJob;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

afterEach(function () {
    Carbon::setTestNow();
});

function componentWithActiveApi(Project $project, User $user, string $status = 'healthy', array $attributes = []): ProjectComponent
{
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'current_status' => $status,
        'last_reported_status' => $status,
        ...$attributes,
    ]);

    MonitorApis::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'is_enabled' => true,
        'current_status' => $status,
        'created_by' => $user->id,
    ]);

    return $component;
}

test('operator can create a manual application component', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Payments App',
        'created_by' => $user->id,
    ]);

    Livewire::test(CreateProjectComponent::class)
        ->fillForm([
            'project_id' => $project->id,
            'name' => 'queue:payments',
            'declared_interval' => 'every_5_minutes',
            'is_archived' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $component = ProjectComponent::query()->where('name', 'queue:payments')->sole();

    expect($component->project_id)->toBe($project->id)
        ->and($component->created_by)->toBe($user->id)
        ->and($component->source)->toBe('manual')
        ->and($component->declared_interval)->toBe('5m')
        ->and($component->interval_minutes)->toBe(5)
        ->and($component->current_status)->toBe('unknown')
        ->and($component->last_reported_status)->toBe('unknown')
        ->and($component->summary)->toBe('Awaiting active child check results')
        ->and($component->last_heartbeat_at)->toBeNull()
        ->and($component->metrics)->toBe([])
        ->and($component->is_archived)->toBeFalse()
        ->and($component->archived_at)->toBeNull();
});

test('operator cannot create a manual component with an explicit non-awaiting status', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);

    Livewire::test(CreateProjectComponent::class)
        ->fillForm([
            'project_id' => $project->id,
            'name' => 'queue:reports',
            'declared_interval' => '5m',
            'current_status' => 'danger',
            'is_archived' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['current_status']);

    expect(ProjectComponent::query()->where('name', 'queue:reports')->exists())->toBeFalse();
});

test('operator can update manual component status interval and archive state', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'source' => 'manual',
        'name' => 'worker:old',
        'declared_interval' => '5m',
        'interval_minutes' => 5,
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
        'summary' => 'Worker is failing',
        'last_heartbeat_at' => now()->subMinutes(10),
        'is_stale' => true,
        'stale_detected_at' => now()->subMinutes(5),
        'is_archived' => false,
        'archived_at' => null,
    ]);

    Livewire::test(EditProjectComponent::class, ['record' => $component->id])
        ->fillForm([
            'name' => 'worker:reports',
            'declared_interval' => '2h',
            'current_status' => 'warning',
            'is_archived' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $component->refresh();

    expect($component->name)->toBe('worker:reports')
        ->and($component->source)->toBe('manual')
        ->and($component->declared_interval)->toBe('2h')
        ->and($component->interval_minutes)->toBe(120)
        ->and($component->current_status)->toBe('unknown')
        ->and($component->last_reported_status)->toBe('unknown')
        ->and($component->summary)->toBe(ProjectComponent::ADMIN_DISABLED_SUMMARY)
        ->and($component->last_heartbeat_at)->toBeNull()
        ->and($component->is_stale)->toBeNull()
        ->and($component->stale_detected_at)->toBeNull()
        ->and($component->is_archived)->toBeTrue()
        ->and($component->archive_reason)->toBe(ProjectComponent::ARCHIVE_REASON_USER)
        ->and($component->archived_at)->not->toBeNull();
});

test('editing a package archived component classifies the archive as user managed', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);
    $component = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'source' => 'package',
        'name' => 'worker:old',
        'declared_interval' => '5m',
        'interval_minutes' => 5,
        'archive_reason' => ProjectComponent::ARCHIVE_REASON_PACKAGE,
    ]);

    Livewire::test(EditProjectComponent::class, ['record' => $component->id])
        ->fillForm([
            'name' => 'worker:reports',
            'declared_interval' => '10m',
            'current_status' => $component->current_status,
            'is_archived' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $component->refresh();

    expect($component->is_archived)->toBeTrue()
        ->and($component->archive_reason)->toBe(ProjectComponent::ARCHIVE_REASON_USER)
        ->and($component->declared_interval)->toBe('10m')
        ->and($component->interval_minutes)->toBe(10);
});

test('operator can reset stored component status to pending', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'source' => 'manual',
        'name' => 'worker:reports',
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinute(),
    ]);

    Livewire::test(EditProjectComponent::class, ['record' => $component->id])
        ->fillForm([
            'current_status' => 'unknown',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $component->refresh();

    expect($component->current_status)->toBe('unknown');
});

test('operator cannot save a manual component with an invalid interval', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);

    Livewire::test(CreateProjectComponent::class)
        ->fillForm([
            'project_id' => $project->id,
            'name' => 'nightly-report',
            'declared_interval' => 'tomorrow',
            'current_status' => 'healthy',
            'is_archived' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['declared_interval']);
});

test('operator cannot create a duplicate component name in the same application', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);
    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'name' => 'queue:payments',
    ]);

    Livewire::test(CreateProjectComponent::class)
        ->fillForm([
            'project_id' => $project->id,
            'name' => 'queue:payments',
            'declared_interval' => '5m',
            'current_status' => 'healthy',
            'is_archived' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(ProjectComponent::query()
        ->where('project_id', $project->id)
        ->where('name', 'queue:payments')
        ->count())->toBe(1);
});

test('operator cannot rename a component to a duplicate name in the same application', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);
    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'name' => 'queue:payments',
    ]);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'name' => 'queue:reports',
    ]);

    Livewire::test(EditProjectComponent::class, ['record' => $component->id])
        ->fillForm([
            'name' => 'queue:payments',
        ])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($component->refresh()->name)->toBe('queue:reports');
});

test('operator cannot create a component for another users application', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->create([
        'created_by' => $otherUser->id,
    ]);

    Livewire::test(CreateProjectComponent::class)
        ->fillForm([
            'project_id' => $otherProject->id,
            'name' => 'foreign-worker',
            'declared_interval' => '5m',
            'current_status' => 'healthy',
            'is_archived' => false,
        ])
        ->call('create')
        ->assertHasFormErrors(['project_id']);

    expect(ProjectComponent::query()->where('created_by', $user->id)->exists())->toBeFalse();
});

test('project application list and component list show active and archived components', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Payments App',
        'created_by' => $user->id,
    ]);

    $activeComponent = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'database',
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);

    $archivedComponent = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'name' => 'legacy-proxy',
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$project]);

    Livewire::test(ListProjectComponents::class)
        ->assertSuccessful()
        ->assertSee($activeComponent->name)
        ->assertSee($archivedComponent->name);
});

test('project component detail hides legacy heartbeat history', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Payments App',
        'created_by' => $user->id,
    ]);

    $archivedComponent = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'name' => 'legacy-proxy',
        'created_by' => $user->id,
        'summary' => 'Legacy proxy is retired',
        'metrics' => [
            'queue_depth' => 17,
            'healthy' => false,
        ],
        'last_heartbeat_at' => now()->subMinutes(10),
        'interval_minutes' => 5,
        'declared_interval' => '5m',
        'is_stale' => true,
        'stale_detected_at' => now()->subMinutes(4),
    ]);

    Livewire::test(ViewProjectComponent::class, ['record' => $archivedComponent->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Derived Health')
        ->assertSee('Package Setup')
        ->assertSee("Checkybot::component('legacy-proxy')")
        ->assertDontSee('Heartbeat History')
        ->assertDontSee('Direct API Heartbeat')
        ->assertDontSee('Stale Threshold')
        ->assertDontSee('Heartbeat expired');
});

test('project component resource does not register the legacy heartbeat relation manager', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Evidence Ordering App',
        'created_by' => $user->id,
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'queue-worker',
        'created_by' => $user->id,
    ]);

    expect(ProjectComponentResource::getRelations())
        ->not->toContain('App\\Filament\\Resources\\ProjectComponents\\RelationManagers\\HeartbeatsRelationManager')
        ->toContain(NotificationSettingsRelationManager::class);
});

test('project component detail hides stale threshold timing', function () {
    config(['monitor.project_component_stale_grace_minutes' => 2]);
    $this->travelTo('2026-04-27 12:00:00');

    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Zero Interval App',
        'created_by' => $user->id,
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'instant-check',
        'created_by' => $user->id,
        'declared_interval' => '0m',
        'interval_minutes' => 0,
        'last_heartbeat_at' => now()->subMinute(),
    ]);

    Livewire::test(ViewProjectComponent::class, ['record' => $component->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Derived Health')
        ->assertDontSee('Stale Threshold')
        ->assertDontSee('Includes 2-minute grace')
        ->assertDontSee('Expires');

    $this->travelBack();
});

test('project component edit page exposes component notification management', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $component = ProjectComponent::factory()->create(['created_by' => $user->id]);

    Livewire::test(EditProjectComponent::class, ['record' => $component->getRouteKey()])
        ->assertSuccessful();

    expect(ProjectComponentResource::getRelations())
        ->toContain(NotificationSettingsRelationManager::class);
});

test('component notification relation manager only shows alerts for the current component', function () {
    $user = $this->actingAsSuperAdmin();
    $component = ProjectComponent::factory()->create(['created_by' => $user->id]);
    $otherComponent = ProjectComponent::factory()->create(['created_by' => $user->id]);

    $visibleSetting = NotificationSetting::factory()->projectComponentScope()->email()->create([
        'user_id' => $user->id,
        'project_component_id' => $component->id,
    ]);
    $hiddenSetting = NotificationSetting::factory()->projectComponentScope()->email()->create([
        'user_id' => $user->id,
        'project_component_id' => $otherComponent->id,
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $component,
        'pageClass' => EditProjectComponent::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visibleSetting])
        ->assertCanNotSeeTableRecords([$hiddenSetting]);
});

test('component notification relation manager filters delivery outcome channel type and inactive rules', function () {
    $user = $this->actingAsSuperAdmin();
    $component = ProjectComponent::factory()->create(['created_by' => $user->id]);

    $failedEmail = NotificationSetting::factory()->projectComponentScope()->email()->create([
        'user_id' => $user->id,
        'project_component_id' => $component->id,
        'last_delivery_succeeded' => false,
        'last_delivery_attempted_at' => now(),
    ]);

    $untestedWebhook = NotificationSetting::factory()->projectComponentScope()->webhook()->create([
        'user_id' => $user->id,
        'project_component_id' => $component->id,
        'last_delivery_attempted_at' => null,
    ]);

    $inactiveEmail = NotificationSetting::factory()->projectComponentScope()->email()->inactive()->create([
        'user_id' => $user->id,
        'project_component_id' => $component->id,
        'last_delivery_succeeded' => true,
        'last_delivery_attempted_at' => now(),
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $component,
        'pageClass' => EditProjectComponent::class,
    ])
        ->filterTable('delivery_outcome', 'failed')
        ->assertCanSeeTableRecords([$failedEmail])
        ->assertCanNotSeeTableRecords([$untestedWebhook, $inactiveEmail]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $component,
        'pageClass' => EditProjectComponent::class,
    ])
        ->filterTable('delivery_outcome', 'untested')
        ->assertCanSeeTableRecords([$untestedWebhook])
        ->assertCanNotSeeTableRecords([$failedEmail, $inactiveEmail]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $component,
        'pageClass' => EditProjectComponent::class,
    ])
        ->filterTable('channel_type', NotificationChannelTypesEnum::MAIL->value)
        ->assertCanSeeTableRecords([$failedEmail, $inactiveEmail])
        ->assertCanNotSeeTableRecords([$untestedWebhook]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $component,
        'pageClass' => EditProjectComponent::class,
    ])
        ->filterTable('rule_state', 'inactive')
        ->assertCanSeeTableRecords([$inactiveEmail])
        ->assertCanNotSeeTableRecords([$failedEmail, $untestedWebhook]);
});

test('super admin can create component-scoped email notification from component page', function () {
    $user = $this->actingAsSuperAdmin();
    $component = ProjectComponent::factory()->create(['created_by' => $user->id]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $component,
        'pageClass' => EditProjectComponent::class,
    ])
        ->callTableAction('create', data: [
            'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH->value,
            'channel_type' => NotificationChannelTypesEnum::MAIL->value,
            'address' => 'workers@example.com',
            'flag_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('notification_settings', [
        'user_id' => $user->id,
        'website_id' => null,
        'monitor_api_id' => null,
        'project_component_id' => $component->id,
        'scope' => NotificationScopesEnum::PROJECT_COMPONENT->value,
        'inspection' => WebsiteServicesEnum::APPLICATION_HEALTH->value,
        'channel_type' => NotificationChannelTypesEnum::MAIL->value,
        'address' => 'workers@example.com',
        'flag_active' => true,
    ]);
});

test('super admin can create application-scoped email notification from project page', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    Livewire::test(ProjectNotificationSettingsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableAction('create', data: [
            'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
            'channel_type' => NotificationChannelTypesEnum::MAIL->value,
            'address' => 'application-alerts@example.com',
            'flag_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('notification_settings', [
        'user_id' => $user->id,
        'project_id' => $project->id,
        'website_id' => null,
        'monitor_api_id' => null,
        'project_component_id' => null,
        'scope' => NotificationScopesEnum::PROJECT->value,
        'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
        'channel_type' => NotificationChannelTypesEnum::MAIL->value,
        'address' => 'application-alerts@example.com',
        'flag_active' => true,
    ]);
});

test('application-scoped webhook notification cannot reuse another users channel', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $otherChannel = NotificationChannels::factory()->create([
        'title' => 'External Hook',
    ]);

    Livewire::test(ProjectNotificationSettingsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableAction('create', data: [
            'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
            'channel_type' => NotificationChannelTypesEnum::WEBHOOK->value,
            'notification_channel_id' => $otherChannel->id,
            'flag_active' => true,
        ])
        ->assertHasTableActionErrors(['notification_channel_id']);

    $this->assertDatabaseMissing('notification_settings', [
        'project_id' => $project->id,
        'notification_channel_id' => $otherChannel->id,
    ]);
});

test('component-scoped webhook notification cannot reuse another users channel', function () {
    $user = $this->actingAsSuperAdmin();
    $component = ProjectComponent::factory()->create(['created_by' => $user->id]);
    $otherChannel = NotificationChannels::factory()->create([
        'title' => 'External Hook',
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $component,
        'pageClass' => EditProjectComponent::class,
    ])
        ->callTableAction('create', data: [
            'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
            'channel_type' => NotificationChannelTypesEnum::WEBHOOK->value,
            'notification_channel_id' => $otherChannel->id,
            'flag_active' => true,
        ])
        ->assertHasTableActionErrors(['notification_channel_id']);

    $this->assertDatabaseMissing('notification_settings', [
        'project_component_id' => $component->id,
        'notification_channel_id' => $otherChannel->id,
    ]);
});

test('project component detail hides stale timing when grace is disabled', function () {
    config(['monitor.project_component_stale_grace_minutes' => 0]);
    $this->travelTo('2026-04-27 12:00:00');

    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'No Grace App',
        'created_by' => $user->id,
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'strict-check',
        'created_by' => $user->id,
        'declared_interval' => '1m',
        'interval_minutes' => 1,
        'last_heartbeat_at' => now()->subMinutes(2),
    ]);

    Livewire::test(ViewProjectComponent::class, ['record' => $component->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Derived Health')
        ->assertDontSee('Stale Threshold')
        ->assertDontSee('Mon, Apr 27, 2026 11:59 AM')
        ->assertDontSee('Expired')
        ->assertDontSee('Includes');

    $this->travelBack();
});

test('application list and detail show the worst active component status', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();

    $healthyProject = Project::factory()->create([
        'name' => 'Healthy App',
        'created_by' => $user->id,
    ]);

    componentWithActiveApi($healthyProject, $user, 'healthy', [
        'name' => 'database',
    ]);

    ProjectComponent::factory()->archived()->create([
        'project_id' => $healthyProject->id,
        'name' => 'legacy-proxy',
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    $dangerProject = Project::factory()->create([
        'name' => 'Danger App',
        'created_by' => $user->id,
    ]);

    componentWithActiveApi($dangerProject, $user, 'warning', [
        'name' => 'queue',
    ]);

    componentWithActiveApi($dangerProject, $user, 'danger', [
        'name' => 'database',
    ]);

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$healthyProject, $dangerProject])
        ->assertSee('Healthy')
        ->assertSee('Failing');

    Livewire::test(ViewProject::class, ['record' => $healthyProject->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Healthy');

    Livewire::test(ViewProject::class, ['record' => $dangerProject->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Failing');
});

test('application list shows and filters setup verification status', function () {
    $this->createResourcePermissions('Project');

    Carbon::setTestNow('2026-05-19 12:00:00');

    $user = $this->actingAsSuperAdmin();

    $waitingForRegistration = Project::factory()->create([
        'name' => 'Unregistered App',
        'created_by' => $user->id,
        'identity_endpoint' => null,
        'package_version' => null,
        'package_key' => null,
        'base_url' => null,
        'repository' => null,
        'last_synced_at' => null,
    ]);

    $whitespaceOnly = Project::factory()->create([
        'name' => 'Whitespace Only App',
        'created_by' => $user->id,
        'identity_endpoint' => '   ',
        'package_version' => '   ',
        'package_key' => '   ',
        'base_url' => '   ',
        'repository' => '   ',
        'last_synced_at' => null,
    ]);

    $waitingForFirstSync = Project::factory()->create([
        'name' => 'Registered App',
        'created_by' => $user->id,
        'identity_endpoint' => 'https://registered.test/checkybot/identity',
        'package_version' => '1.2.0',
        'package_key' => null,
        'base_url' => null,
        'repository' => null,
        'last_synced_at' => null,
    ]);

    $syncStale = Project::factory()->create([
        'name' => 'Stale Sync App',
        'created_by' => $user->id,
        'package_key' => 'stale-sync-app',
        'last_synced_at' => now()->subMinutes(20),
    ]);

    $synced = Project::factory()->create([
        'name' => 'Synced App',
        'created_by' => $user->id,
        'package_key' => 'synced-app',
        'last_synced_at' => now()->subMinutes(5),
    ]);

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertSee('Waiting for registration')
        ->assertSee('Waiting for first sync')
        ->assertSee('Sync stale')
        ->assertSee('Synced')
        ->assertSee('Create or copy an API key, install the package, then run the first sync.')
        ->assertSee('Run the package sync command once, then confirm the scheduler is active.')
        ->assertSee('Run the sync command now, then verify the scheduler and Laravel logs.')
        ->assertTableActionVisible('setup_runbook', $waitingForRegistration)
        ->assertTableActionHasLabel('setup_runbook', 'Finish registration', $waitingForRegistration)
        ->assertTableActionVisible('setup_runbook', $waitingForFirstSync)
        ->assertTableActionHasLabel('setup_runbook', 'Run first sync', $waitingForFirstSync)
        ->assertTableActionVisible('setup_runbook', $syncStale)
        ->assertTableActionHasLabel('setup_runbook', 'Run sync repair', $syncStale)
        ->assertTableActionHidden('setup_runbook', $synced);

    Livewire::test(ListProjects::class)
        ->filterTable('setup_verification_state', 'waiting_for_registration')
        ->assertCanSeeTableRecords([$waitingForRegistration, $whitespaceOnly])
        ->assertCanNotSeeTableRecords([$waitingForFirstSync, $syncStale, $synced]);

    Livewire::test(ListProjects::class)
        ->filterTable('setup_verification_state', 'waiting_for_first_sync')
        ->assertCanSeeTableRecords([$waitingForFirstSync])
        ->assertCanNotSeeTableRecords([$waitingForRegistration, $whitespaceOnly, $syncStale, $synced]);

    Livewire::test(ListProjects::class)
        ->filterTable('setup_verification_state', 'sync_stale')
        ->assertCanSeeTableRecords([$syncStale])
        ->assertCanNotSeeTableRecords([$waitingForRegistration, $whitespaceOnly, $waitingForFirstSync, $synced]);

    Livewire::test(ListProjects::class)
        ->filterTable('setup_verification_state', 'synced')
        ->assertCanSeeTableRecords([$synced])
        ->assertCanNotSeeTableRecords([$waitingForRegistration, $whitespaceOnly, $waitingForFirstSync, $syncStale]);
});

test('application detail shows package sync status metadata', function () {
    $this->createResourcePermissions('Project');

    $user = $this->actingAsSuperAdmin();
    $syncedAt = now()->subMinutes(12);
    $componentSyncedAt = now()->subMinutes(3);
    $project = Project::factory()->create([
        'name' => 'Checkout App',
        'created_by' => $user->id,
        'package_key' => 'checkout-app',
        'package_version' => '1.2.3',
        'base_url' => 'https://checkout.example.com',
        'repository' => 'marin-solutions/checkout',
        'last_synced_at' => $syncedAt,
        'last_component_synced_at' => $componentSyncedAt,
        'latest_package_sync_summary' => [
            'created' => 2,
            'updated' => 1,
            'disabled_missing' => 1,
            'api_checks' => [
                'created' => 1,
                'updated' => 0,
                'disabled_missing' => 1,
            ],
            'uptime_checks' => [
                'created' => 1,
                'updated' => 1,
                'disabled_missing' => 0,
            ],
            'ssl_checks' => [
                'created' => 0,
                'updated' => 0,
                'disabled_missing' => 0,
            ],
        ],
        'latest_component_sync_summary' => [
            'components' => [
                'created' => 2,
                'updated' => 1,
                'archived' => 1,
            ],
        ],
    ]);

    Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'homepage',
        'created_by' => $user->id,
    ]);

    $archivedPackageWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'legacy-homepage',
        'created_by' => $user->id,
    ]);
    $archivedPackageWebsite->delete();

    Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'manual',
        'created_by' => $user->id,
    ]);

    MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'health',
        'created_by' => $user->id,
    ]);

    MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'manual',
        'created_by' => $user->id,
    ]);

    ProjectComponent::factory()->count(2)->create([
        'project_id' => $project->id,
        'source' => 'package',
        'created_by' => $user->id,
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'source' => 'manual',
        'created_by' => $user->id,
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Package Sync Status')
        ->assertSee($syncedAt->toDayDateTimeString())
        ->assertSee($componentSyncedAt->toDayDateTimeString())
        ->assertSee('SDK Version')
        ->assertSee('1.2.3')
        ->assertSee('checkout-app')
        ->assertSee('https://checkout.example.com')
        ->assertSee('marin-solutions/checkout')
        ->assertSeeInOrder(['Synced Checks', '3'])
        ->assertSeeInOrder(['Synced Components', '2'])
        ->assertSeeInOrder(['Last Sync Changes', '2 created, 1 updated, 1 disabled'])
        ->assertSee('API checks: 1 created, 1 disabled')
        ->assertSee('Uptime checks: 1 created, 1 updated')
        ->assertSee('SSL checks: no changes')
        ->assertSeeInOrder(['Last Component Sync Changes', '2 created, 1 updated, 1 archived'])
        ->assertSee('Components: 2 created, 1 updated, 1 archived')
        ->assertDontSee('Heartbeats:');
});

test('application detail action queues enabled package managed diagnostics', function () {
    $this->createResourcePermissions('Project');
    Bus::fake();
    $this->travelTo('2026-05-09 12:00:00');

    $owner = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Checkout App',
        'created_by' => $owner->id,
        'package_key' => 'checkout-app',
    ]);

    $apiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'source' => 'package',
        'package_name' => 'search-health',
        'is_enabled' => true,
    ]);

    $disabledApiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'source' => 'package',
        'package_name' => 'disabled-health',
        'is_enabled' => false,
    ]);

    $website = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'source' => 'package',
        'package_name' => 'landing-page',
        'uptime_check' => true,
        'ssl_check' => true,
    ]);

    $pausedWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'source' => 'package',
        'package_name' => 'paused-landing-page',
        'uptime_check' => false,
        'ssl_check' => false,
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertActionVisible('run_diagnostics')
        ->callAction('run_diagnostics')
        ->assertNotified('Diagnostics queued');

    Bus::assertBatched(function ($batch) use ($project): bool {
        return $batch->name === 'Control project run: checkout-app'
            && $batch->jobs->count() === 2
            && $batch->jobs->contains(fn ($job): bool => $job instanceof RunApiMonitorDiagnosticJob
                && $job->monitor->package_name === 'search-health')
            && $batch->jobs->contains(fn ($job): bool => $job instanceof LogUptimeSslJob
                && $job->website->package_name === 'landing-page'
                && $job->onDemand === true)
            && ($batch->options['checkybot_control']['project_id'] ?? null) === $project->id
            && ($batch->options['checkybot_control']['user_id'] ?? null) === $project->created_by
            && ($batch->options['allowFailures'] ?? false) === true;
    });

    expect($apiMonitor->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-09 12:00:00')
        ->and($website->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-09 12:00:00')
        ->and($disabledApiMonitor->refresh()->diagnostic_queued_at)->toBeNull()
        ->and($pausedWebsite->refresh()->diagnostic_queued_at)->toBeNull()
        ->and($project->refresh()->latest_diagnostic_run_batch_id)->not->toBeNull()
        ->and($project->latest_diagnostic_run_batch_queued_at?->toDateTimeString())->toBe('2026-05-09 12:00:00');
});

test('application detail displays latest diagnostic batch progress', function () {
    $this->createResourcePermissions('Project');
    $queuedAt = Carbon::parse('2026-05-09 12:00:00');
    $createdAt = $queuedAt->copy()->subMinute()->timestamp;

    $owner = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Checkout App',
        'created_by' => $owner->id,
        'package_key' => 'checkout-app',
        'latest_diagnostic_run_batch_id' => 'batch-checkout-app',
        'latest_diagnostic_run_batch_queued_at' => $queuedAt,
    ]);

    DB::table('job_batches')->insert([
        'id' => 'batch-checkout-app',
        'name' => 'Control project run: checkout-app',
        'total_jobs' => 4,
        'pending_jobs' => 1,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['failed-job-1']),
        'options' => serialize([
            'allowFailures' => true,
            'checkybot_control' => [
                'project_id' => $project->id,
                'user_id' => $owner->id,
            ],
        ]),
        'cancelled_at' => null,
        'created_at' => $createdAt,
        'finished_at' => null,
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Application Diagnostics')
        ->assertSee('Latest diagnostic batch')
        ->assertSee('Running')
        ->assertSee('3 of 4 queued checks have finished.')
        ->assertSee('1 check failed during execution.')
        ->assertSeeInOrder(['Total', '4'])
        ->assertSeeInOrder(['Pending', '1'])
        ->assertSeeInOrder(['Failed', '1'])
        ->assertSee('batch-checkout-app');
});

test('application detail action reports when no enabled diagnostics can be queued', function () {
    $this->createResourcePermissions('Project');
    Bus::fake();

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
        'package_key' => 'checkout-app',
    ]);

    MonitorApis::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'source' => 'package',
        'package_name' => 'disabled-health',
        'is_enabled' => false,
    ]);

    Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'source' => 'package',
        'package_name' => 'paused-landing-page',
        'uptime_check' => false,
        'ssl_check' => false,
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->callAction('run_diagnostics')
        ->assertNotified('No enabled diagnostics');

    Bus::assertNothingBatched();
});

test('application detail hides diagnostics action without project update permission', function () {
    $this->createResourcePermissions('Project');

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo(['ViewAny:Project', 'View:Project']);
    $this->actingAs($user);

    $project = Project::factory()->create([
        'created_by' => $user->id,
        'package_key' => 'checkout-app',
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertActionHidden('run_diagnostics');
});

test('application detail hides diagnostics action without child monitor update permissions', function () {
    $this->createResourcePermissions('Project');

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo(['ViewAny:Project', 'View:Project', 'Update:Project']);
    $this->actingAs($user);

    $project = Project::factory()->create([
        'created_by' => $user->id,
        'package_key' => 'checkout-app',
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertActionHidden('run_diagnostics');
});

test('application detail shows sdk version for registered applications before package sync', function () {
    $this->createResourcePermissions('Project');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Registered App',
        'created_by' => $user->id,
        'package_key' => null,
        'package_version' => '1.2.3',
        'last_synced_at' => null,
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Package Sync Status')
        ->assertSeeInOrder(['Last Synced', 'Never'])
        ->assertSeeInOrder(['SDK Version', '1.2.3'])
        ->assertSeeInOrder(['Package Key', '-']);
});

test('application detail summarizes legacy package sync changes from per type buckets', function () {
    $this->createResourcePermissions('Project');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Legacy Sync App',
        'created_by' => $user->id,
        'package_key' => 'legacy-sync-app',
        'last_synced_at' => now()->subMinutes(4),
        'latest_package_sync_summary' => [
            'uptime_checks' => [
                'created' => 1,
                'updated' => 0,
                'deleted' => 1,
            ],
            'ssl_checks' => [
                'created' => 0,
                'updated' => 2,
                'deleted' => 0,
            ],
            'api_checks' => [
                'created' => 3,
                'updated' => 0,
                'deleted' => 1,
            ],
        ],
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertSeeInOrder(['Last Sync Changes', '4 created, 2 updated, 2 disabled'])
        ->assertSee('Uptime checks: 1 created, 1 disabled')
        ->assertSee('SSL checks: 2 updated')
        ->assertSee('API checks: 3 created, 1 disabled');
});

test('application detail hides package sync status for manual applications', function () {
    $this->createResourcePermissions('Project');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Manual App',
        'created_by' => $user->id,
        'package_key' => null,
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertDontSee('Package Sync Status')
        ->assertDontSee('Latest package sync metadata for diagnosing stale or incomplete application integrations.');
});

test('application detail shows unsynced package applications as never synced', function () {
    $this->createResourcePermissions('Project');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Unsynced Package App',
        'created_by' => $user->id,
        'package_key' => 'unsynced-package-app',
        'last_synced_at' => null,
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Package Sync Status')
        ->assertSeeInOrder(['Last Synced', 'Never']);
});

test('application record shows package-managed external checks including archived ones', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Payments App',
        'created_by' => $user->id,
        'package_key' => 'payments-app',
    ]);

    $uptimeWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'homepage',
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(2),
        'status_summary' => 'Homepage heartbeat succeeded with HTTP status 200.',
        'uptime_check' => true,
        'ssl_check' => false,
        'created_by' => $user->id,
    ]);

    $archivedSslWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'certificate',
        'source' => 'package',
        'package_name' => 'certificate',
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(8),
        'stale_at' => now()->subMinutes(3),
        'status_summary' => 'No heartbeat received within the expected 5m interval.',
        'uptime_check' => true,
        'ssl_check' => true,
        'created_by' => $user->id,
    ]);
    $archivedSslWebsite->delete();

    $disabledWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'removed-site',
        'source' => 'package',
        'package_name' => 'removed-site',
        'package_interval' => null,
        'last_heartbeat_at' => null,
        'stale_at' => null,
        'status_summary' => 'Disabled because it was missing from the latest package sync.',
        'uptime_check' => false,
        'ssl_check' => false,
        'created_by' => $user->id,
    ]);

    $apiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'health',
        'source' => 'package',
        'package_name' => 'health',
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(1),
        'status_summary' => 'API check succeeded with HTTP status 200.',
        'created_by' => $user->id,
    ]);

    $archivedApiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'legacy-health',
        'source' => 'package',
        'package_name' => 'legacy-health',
        'package_interval' => '15m',
        'is_enabled' => false,
        'last_heartbeat_at' => null,
        'status_summary' => 'Awaiting first package heartbeat.',
        'created_by' => $user->id,
    ]);
    $archivedApiMonitor->delete();

    $disabledApiMonitor = MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'title' => 'paused-health',
        'source' => 'package',
        'package_name' => 'paused-health',
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(20),
        'stale_at' => now()->subMinutes(10),
        'status_summary' => 'Disabled by Checkybot control API.',
        'created_by' => $user->id,
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertSeeInOrder(['Synced Checks', '7'])
        ->assertSee('Package-managed Websites')
        ->assertSee('Package-managed APIs');

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$uptimeWebsite, $archivedSslWebsite, $disabledWebsite])
        ->assertTableColumnStateSet('deleted_at', 'Disabled', $disabledWebsite)
        ->assertSee('Summary')
        ->assertSee('Last Check')
        ->assertDontSee('Freshness')
        ->assertSee('Homepage heartbeat succeeded with HTTP status 200.')
        ->assertSee('No heartbeat received within the expected 5m interval.')
        ->assertSee('Disabled because it was missing from the latest package sync.')
        ->assertSee('Both uptime and SSL checks are disabled. Scheduled runs are paused.');

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$apiMonitor, $archivedApiMonitor, $disabledApiMonitor])
        ->assertTableColumnStateSet('deleted_at', 'Active', $apiMonitor)
        ->assertTableColumnStateSet('deleted_at', 'Archived', $archivedApiMonitor)
        ->assertTableColumnStateSet('deleted_at', 'Disabled', $disabledApiMonitor)
        ->assertSee('Summary')
        ->assertSee('Last Scheduled Check')
        ->assertDontSee('Freshness')
        ->assertSee('API check succeeded with HTTP status 200.')
        ->assertSee('Awaiting first package heartbeat.')
        ->assertSee('This check is disabled. Scheduled runs are paused.')
        ->assertDontSee('Monitor is disabled. Scheduled API checks are not expected.');

    expect(ProjectResource::getRelations())
        ->toContain(PackageManagedWebsitesRelationManager::class)
        ->toContain(PackageManagedApisRelationManager::class);
});

test('package-managed relation managers do not expose non-server freshness filters', function () {
    Carbon::setTestNow('2026-05-12 12:00:00');

    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
        'package_key' => 'triage-app',
    ]);

    $website = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'legacy-stale-site',
        'source' => 'package',
        'package_name' => 'legacy-stale-site',
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(2),
        'stale_at' => now()->subMinute(),
        'uptime_check' => true,
        'ssl_check' => false,
        'created_by' => $user->id,
    ]);

    $api = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'legacy-stale-api',
        'source' => 'package',
        'package_name' => 'legacy-stale-api',
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(2),
        'stale_at' => now()->subMinute(),
        'is_enabled' => true,
        'created_by' => $user->id,
    ]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertCanSeeTableRecords([$website])
        ->assertDontSee('Freshness')
        ->assertDontSee('Awaiting heartbeat');

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertCanSeeTableRecords([$api])
        ->assertDontSee('Freshness')
        ->assertDontSee('Awaiting check');
});

test('super admin can filter package-managed websites by health and state', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $healthy = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'healthy-homepage',
        'source' => 'package',
        'package_name' => 'healthy-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    $warning = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'slow-homepage',
        'source' => 'package',
        'package_name' => 'slow-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);
    $pending = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'new-homepage',
        'source' => 'package',
        'package_name' => 'new-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => null,
        'created_by' => $user->id,
    ]);
    $disabled = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'disabled-homepage',
        'source' => 'package',
        'package_name' => 'disabled-homepage',
        'uptime_check' => false,
        'ssl_check' => false,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    $archived = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'archived-homepage',
        'source' => 'package',
        'package_name' => 'archived-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    $archived->delete();

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('current_status', 'warning')
        ->assertCanSeeTableRecords([$warning])
        ->assertCanNotSeeTableRecords([$healthy, $pending, $disabled, $archived]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('current_status', 'unknown')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$healthy, $warning, $disabled, $archived]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('monitoring_state', 'active')
        ->assertCanSeeTableRecords([$healthy, $warning, $pending])
        ->assertCanNotSeeTableRecords([$disabled, $archived]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('monitoring_state', 'disabled')
        ->assertCanSeeTableRecords([$disabled])
        ->assertCanNotSeeTableRecords([$healthy, $warning, $pending, $archived]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('monitoring_state', 'archived')
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$healthy, $warning, $pending, $disabled]);
});

test('super admin can filter package-managed api monitors by health and state', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $healthy = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'healthy-api',
        'source' => 'package',
        'package_name' => 'healthy-api',
        'is_enabled' => true,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    $warning = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'slow-api',
        'source' => 'package',
        'package_name' => 'slow-api',
        'is_enabled' => true,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);
    $pending = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'new-api',
        'source' => 'package',
        'package_name' => 'new-api',
        'is_enabled' => true,
        'current_status' => null,
        'created_by' => $user->id,
    ]);
    $disabled = MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'title' => 'disabled-api',
        'source' => 'package',
        'package_name' => 'disabled-api',
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    $archived = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'archived-api',
        'source' => 'package',
        'package_name' => 'archived-api',
        'is_enabled' => true,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    $archived->delete();

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('current_status', 'warning')
        ->assertCanSeeTableRecords([$warning])
        ->assertCanNotSeeTableRecords([$healthy, $pending, $disabled, $archived]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('current_status', 'unknown')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$healthy, $warning, $disabled, $archived]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('monitoring_state', 'active')
        ->assertCanSeeTableRecords([$healthy, $warning, $pending])
        ->assertCanNotSeeTableRecords([$disabled, $archived]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('monitoring_state', 'disabled')
        ->assertCanSeeTableRecords([$disabled])
        ->assertCanNotSeeTableRecords([$healthy, $warning, $pending, $archived]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('monitoring_state', 'archived')
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$healthy, $warning, $pending, $disabled]);
});

test('package-managed relation managers surface manual run drift', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $driftingWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'drifting-homepage',
        'source' => 'package',
        'package_name' => 'drifting-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    WebsiteLogHistory::factory()->onDemand()->create([
        'website_id' => $driftingWebsite->id,
        'status' => 'danger',
        'summary' => 'Manual homepage failed.',
        'created_at' => now()->subMinute(),
    ]);

    $matchingWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'matching-homepage',
        'source' => 'package',
        'package_name' => 'matching-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    WebsiteLogHistory::factory()->onDemand()->create([
        'website_id' => $matchingWebsite->id,
        'status' => 'healthy',
        'created_at' => now()->subMinute(),
    ]);

    $missingWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'missing-manual-homepage',
        'source' => 'package',
        'package_name' => 'missing-manual-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);

    $queuedWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'queued-homepage',
        'source' => 'package',
        'package_name' => 'queued-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
        'diagnostic_queued_at' => now(),
        'created_by' => $user->id,
    ]);
    WebsiteLogHistory::factory()->onDemand()->create([
        'website_id' => $queuedWebsite->id,
        'status' => 'healthy',
        'created_at' => now()->subMinute(),
    ]);

    $queuedWebsiteWithoutResult = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'queued-new-homepage',
        'source' => 'package',
        'package_name' => 'queued-new-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
        'diagnostic_queued_at' => now(),
        'created_by' => $user->id,
    ]);

    $driftingApi = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'drifting-api',
        'source' => 'package',
        'package_name' => 'drifting-api',
        'is_enabled' => true,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    MonitorApiResult::factory()->onDemand()->create([
        'monitor_api_id' => $driftingApi->id,
        'status' => 'danger',
        'summary' => 'Manual API failed.',
        'created_at' => now()->subMinute(),
    ]);

    $matchingApi = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'matching-api',
        'source' => 'package',
        'package_name' => 'matching-api',
        'is_enabled' => true,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    MonitorApiResult::factory()->onDemand()->create([
        'monitor_api_id' => $matchingApi->id,
        'status' => 'healthy',
        'created_at' => now()->subMinute(),
    ]);

    $missingApi = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'missing-manual-api',
        'source' => 'package',
        'package_name' => 'missing-manual-api',
        'is_enabled' => true,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);

    $queuedApi = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'queued-api',
        'source' => 'package',
        'package_name' => 'queued-api',
        'is_enabled' => true,
        'current_status' => 'healthy',
        'diagnostic_queued_at' => now(),
        'created_by' => $user->id,
    ]);
    MonitorApiResult::factory()->onDemand()->create([
        'monitor_api_id' => $queuedApi->id,
        'status' => 'healthy',
        'created_at' => now()->subMinute(),
    ]);

    $queuedApiWithoutResult = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'queued-new-api',
        'source' => 'package',
        'package_name' => 'queued-new-api',
        'is_enabled' => true,
        'current_status' => 'healthy',
        'diagnostic_queued_at' => now(),
        'created_by' => $user->id,
    ]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableColumnExists('latestDiagnosticLogHistory.status')
        ->assertSee('Manual')
        ->assertTableColumnStateSet('latestDiagnosticLogHistory.status', 'danger', $driftingWebsite)
        ->assertTableColumnStateSet('latestDiagnosticLogHistory.status', 'Queued', $queuedWebsite)
        ->assertTableColumnStateSet('latestDiagnosticLogHistory.status', 'Queued', $queuedWebsiteWithoutResult)
        ->assertSee('Differs from live health.')
        ->filterTable('manual_run_status', 'drift')
        ->assertCanSeeTableRecords([$driftingWebsite])
        ->assertCanNotSeeTableRecords([$matchingWebsite, $missingWebsite, $queuedWebsite, $queuedWebsiteWithoutResult]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('manual_run_status', 'matching')
        ->assertCanSeeTableRecords([$matchingWebsite])
        ->assertCanNotSeeTableRecords([$driftingWebsite, $missingWebsite, $queuedWebsite, $queuedWebsiteWithoutResult]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('manual_run_status', 'missing')
        ->assertCanSeeTableRecords([$missingWebsite])
        ->assertCanNotSeeTableRecords([$driftingWebsite, $matchingWebsite, $queuedWebsite, $queuedWebsiteWithoutResult]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('manual_run_status', 'queued')
        ->assertCanSeeTableRecords([$queuedWebsite, $queuedWebsiteWithoutResult])
        ->assertCanNotSeeTableRecords([$driftingWebsite, $matchingWebsite, $missingWebsite]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableColumnExists('latestDiagnosticResult.status')
        ->assertSee('Manual')
        ->assertTableColumnStateSet('latestDiagnosticResult.status', 'danger', $driftingApi)
        ->assertTableColumnStateSet('latestDiagnosticResult.status', 'Queued', $queuedApi)
        ->assertTableColumnStateSet('latestDiagnosticResult.status', 'Queued', $queuedApiWithoutResult)
        ->assertSee('Differs from live health.')
        ->filterTable('manual_run_status', 'drift')
        ->assertCanSeeTableRecords([$driftingApi])
        ->assertCanNotSeeTableRecords([$matchingApi, $missingApi, $queuedApi, $queuedApiWithoutResult]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('manual_run_status', 'matching')
        ->assertCanSeeTableRecords([$matchingApi])
        ->assertCanNotSeeTableRecords([$driftingApi, $missingApi, $queuedApi, $queuedApiWithoutResult]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('manual_run_status', 'missing')
        ->assertCanSeeTableRecords([$missingApi])
        ->assertCanNotSeeTableRecords([$driftingApi, $matchingApi, $queuedApi, $queuedApiWithoutResult]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('manual_run_status', 'queued')
        ->assertCanSeeTableRecords([$queuedApi, $queuedApiWithoutResult])
        ->assertCanNotSeeTableRecords([$driftingApi, $matchingApi, $missingApi]);
});

test('package-managed relation managers queue run now diagnostics for active checks', function () {
    Carbon::setTestNow('2026-05-10 12:00:00');

    $user = $this->actingAsSuperAdmin();
    Queue::fake();

    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);

    $website = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'homepage',
        'package_interval' => '5m',
        'uptime_check' => true,
        'ssl_check' => false,
        'created_by' => $user->id,
    ]);

    $apiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'package_interval' => '5m',
        'is_enabled' => true,
        'created_by' => $user->id,
    ]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionExists('run_now', null, $website)
        ->assertTableActionHasLabel('run_now', 'Run check now', $website)
        ->assertTableActionHasIcon('run_now', 'heroicon-o-bolt', $website)
        ->callTableAction('run_now', $website)
        ->assertNotified('Diagnostic queued');

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionExists('run_now', null, $apiMonitor)
        ->assertTableActionHasLabel('run_now', 'Run check now', $apiMonitor)
        ->assertTableActionHasIcon('run_now', 'heroicon-o-bolt', $apiMonitor)
        ->callTableAction('run_now', $apiMonitor)
        ->assertNotified('Diagnostic queued');

    Queue::assertPushed(LogUptimeSslJob::class, fn (LogUptimeSslJob $job): bool => $job->website->is($website) && $job->onDemand === true);
    Queue::assertPushed(RunApiMonitorDiagnosticJob::class, fn (RunApiMonitorDiagnosticJob $job): bool => $job->monitor->is($apiMonitor));

    expect($website->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-10 12:00:00')
        ->and($apiMonitor->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-10 12:00:00');
});

test('package-managed relation managers bulk queue selected active diagnostics', function () {
    Carbon::setTestNow('2026-05-10 12:00:00');

    $user = $this->actingAsSuperAdmin();
    Queue::fake();

    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);

    $activeWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'created_by' => $user->id,
    ]);
    $disabledWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'disabled-homepage',
        'uptime_check' => false,
        'ssl_check' => false,
        'created_by' => $user->id,
    ]);
    $queuedWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'queued-homepage',
        'uptime_check' => true,
        'diagnostic_queued_at' => now()->subMinute(),
        'created_by' => $user->id,
    ]);
    $archivedWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'archived-homepage',
        'uptime_check' => true,
        'created_by' => $user->id,
    ]);
    $archivedWebsite->delete();

    $activeApiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'is_enabled' => true,
        'created_by' => $user->id,
    ]);
    $disabledApiMonitor = MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'disabled-api',
        'created_by' => $user->id,
    ]);
    $queuedApiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'queued-api',
        'is_enabled' => true,
        'diagnostic_queued_at' => now()->subMinute(),
        'created_by' => $user->id,
    ]);
    $archivedApiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'archived-api',
        'is_enabled' => true,
        'created_by' => $user->id,
    ]);
    $archivedApiMonitor->delete();

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableBulkActionExists('run_selected_diagnostics')
        ->assertTableBulkActionHasLabel('run_selected_diagnostics', 'Run selected diagnostics')
        ->callTableBulkAction('run_selected_diagnostics', collect([
            $activeWebsite,
            $disabledWebsite,
            $queuedWebsite,
            $archivedWebsite,
        ]))
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('1 diagnostic queued');

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableBulkActionExists('run_selected_diagnostics')
        ->assertTableBulkActionHasLabel('run_selected_diagnostics', 'Run selected diagnostics')
        ->callTableBulkAction('run_selected_diagnostics', collect([
            $activeApiMonitor,
            $disabledApiMonitor,
            $queuedApiMonitor,
            $archivedApiMonitor,
        ]))
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('1 diagnostic queued');

    Queue::assertPushed(LogUptimeSslJob::class, fn (LogUptimeSslJob $job): bool => $job->website->is($activeWebsite) && $job->onDemand === true);
    Queue::assertPushed(RunApiMonitorDiagnosticJob::class, fn (RunApiMonitorDiagnosticJob $job): bool => $job->monitor->is($activeApiMonitor));
    Queue::assertPushed(LogUptimeSslJob::class, 1);
    Queue::assertPushed(RunApiMonitorDiagnosticJob::class, 1);

    expect($activeWebsite->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-10 12:00:00')
        ->and($disabledWebsite->refresh()->diagnostic_queued_at)->toBeNull()
        ->and($queuedWebsite->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-10 11:59:00')
        ->and(Website::withTrashed()->find($archivedWebsite->id)->diagnostic_queued_at)->toBeNull()
        ->and($activeApiMonitor->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-10 12:00:00')
        ->and($disabledApiMonitor->refresh()->diagnostic_queued_at)->toBeNull()
        ->and($queuedApiMonitor->refresh()->diagnostic_queued_at?->toDateTimeString())->toBe('2026-05-10 11:59:00')
        ->and(MonitorApis::withTrashed()->find($archivedApiMonitor->id)->diagnostic_queued_at)->toBeNull();
});

test('package-managed website checks can be snoozed and unsnoozed from application detail', function () {
    Carbon::setTestNow('2026-05-10 12:00:00');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);
    $website = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'homepage',
        'uptime_check' => true,
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableColumnExists('silenced_until')
        ->assertTableActionExists('snooze', null, $website)
        ->assertTableActionHasIcon('snooze', 'heroicon-o-bell-slash', $website)
        ->callTableAction('snooze', $website, data: [
            'duration' => '1h',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Notifications snoozed');

    expect($website->refresh()->silenced_until?->toDateTimeString())->toBe('2026-05-10 13:00:00');

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionVisible('unsnooze', $website)
        ->callTableAction('unsnooze', $website)
        ->assertHasNoTableActionErrors()
        ->assertNotified('Notifications resumed');

    expect($website->refresh()->silenced_until)->toBeNull();
});

test('package-managed api checks can be snoozed and unsnoozed from application detail', function () {
    Carbon::setTestNow('2026-05-10 12:00:00');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);
    $apiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'is_enabled' => true,
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableColumnExists('silenced_until')
        ->assertTableActionExists('snooze', null, $apiMonitor)
        ->assertTableActionHasIcon('snooze', 'heroicon-o-bell-slash', $apiMonitor)
        ->callTableAction('snooze', $apiMonitor, data: [
            'duration' => '1h',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Notifications snoozed');

    expect($apiMonitor->refresh()->silenced_until?->toDateTimeString())->toBe('2026-05-10 13:00:00');

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionVisible('unsnooze', $apiMonitor)
        ->callTableAction('unsnooze', $apiMonitor)
        ->assertHasNoTableActionErrors()
        ->assertNotified('Notifications resumed');

    expect($apiMonitor->refresh()->silenced_until)->toBeNull();
});

test('admin without monitor update permissions cannot snooze package-managed checks from application detail', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('MonitorApis');

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo([
        'ViewAny:Project',
        'View:Project',
        'ViewAny:Website',
        'View:Website',
        'ViewAny:MonitorApis',
        'View:MonitorApis',
    ]);
    $this->actingAs($user);

    $project = Project::factory()->create(['created_by' => $user->id]);
    $website = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'homepage',
        'created_by' => $user->id,
        'silenced_until' => now()->addHour(),
    ]);
    $apiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'api-health',
        'created_by' => $user->id,
        'silenced_until' => now()->addHour(),
    ]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionHidden('snooze')
        ->assertTableActionHidden('unsnooze', $website)
        ->assertTableBulkActionHidden('assign_component')
        ->assertTableBulkActionHidden('run_selected_diagnostics');

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionHidden('snooze')
        ->assertTableActionHidden('unsnooze', $apiMonitor)
        ->assertTableBulkActionHidden('assign_component')
        ->assertTableBulkActionHidden('run_selected_diagnostics');
});

test('package-managed relation managers guard run now diagnostics for inactive checks', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'created_by' => $user->id,
    ]);

    $queuedWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'queued-homepage',
        'uptime_check' => true,
        'diagnostic_queued_at' => now(),
        'created_by' => $user->id,
    ]);
    $disabledWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'disabled-homepage',
        'uptime_check' => false,
        'ssl_check' => false,
        'created_by' => $user->id,
    ]);
    $archivedWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'archived-homepage',
        'uptime_check' => true,
        'created_by' => $user->id,
    ]);
    $archivedWebsite->delete();

    $queuedApiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'queued-api',
        'is_enabled' => true,
        'diagnostic_queued_at' => now(),
        'created_by' => $user->id,
    ]);
    $disabledApiMonitor = MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'disabled-api',
        'created_by' => $user->id,
    ]);
    $archivedApiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'package_name' => 'archived-api',
        'is_enabled' => true,
        'created_by' => $user->id,
    ]);
    $archivedApiMonitor->delete();

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionDisabled('run_now', $queuedWebsite)
        ->assertTableActionHidden('run_now', $disabledWebsite)
        ->assertTableActionHidden('run_now', $archivedWebsite);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionDisabled('run_now', $queuedApiMonitor)
        ->assertTableActionHidden('run_now', $disabledApiMonitor)
        ->assertTableActionHidden('run_now', $archivedApiMonitor);
});

test('super admin can filter applications by current status', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();

    $healthyProject = Project::factory()->create(['name' => 'Healthy App', 'created_by' => $user->id]);
    componentWithActiveApi($healthyProject, $user, 'healthy');

    $warningProject = Project::factory()->create(['name' => 'Warning App', 'created_by' => $user->id]);
    componentWithActiveApi($warningProject, $user, 'warning');

    $dangerProject = Project::factory()->create(['name' => 'Danger App', 'created_by' => $user->id]);
    componentWithActiveApi($dangerProject, $user, 'warning');
    componentWithActiveApi($dangerProject, $user, 'danger');

    $derivedDangerProject = Project::factory()->create(['name' => 'Derived Danger App', 'created_by' => $user->id]);
    componentWithActiveApi($derivedDangerProject, $user, 'danger', [
        'current_status' => 'healthy',
        'last_reported_status' => 'healthy',
    ]);

    $storedDangerHealthyProject = Project::factory()->create(['name' => 'Stored Danger Healthy App', 'created_by' => $user->id]);
    componentWithActiveApi($storedDangerHealthyProject, $user, 'healthy', [
        'current_status' => 'danger',
        'last_reported_status' => 'danger',
    ]);

    $staleComponentProject = Project::factory()->create(['name' => 'Stale Component App', 'created_by' => $user->id]);
    componentWithActiveApi($staleComponentProject, $user, 'healthy', [
        'is_stale' => true,
        'stale_detected_at' => now()->subMinute(),
    ]);

    $unknownProject = Project::factory()->create(['name' => 'Unknown App', 'created_by' => $user->id]);

    $websiteDangerProject = Project::factory()->create(['name' => 'Website Danger App', 'created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $websiteDangerProject->id,
        'uptime_check' => true,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    $staleWebsiteProject = Project::factory()->create(['name' => 'Stale Website App', 'created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $staleWebsiteProject->id,
        'uptime_check' => true,
        'current_status' => 'healthy',
        'stale_at' => now()->subMinute(),
        'created_by' => $user->id,
    ]);

    $sslOnlyDangerProject = Project::factory()->create(['name' => 'SSL Danger App', 'created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $sslOnlyDangerProject->id,
        'uptime_check' => false,
        'ssl_check' => true,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    $apiWarningProject = Project::factory()->create(['name' => 'API Warning App', 'created_by' => $user->id]);
    MonitorApis::factory()->create([
        'project_id' => $apiWarningProject->id,
        'is_enabled' => true,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);

    $staleApiProject = Project::factory()->create(['name' => 'Stale API App', 'created_by' => $user->id]);
    MonitorApis::factory()->create([
        'project_id' => $staleApiProject->id,
        'is_enabled' => true,
        'current_status' => 'healthy',
        'stale_at' => now()->subMinute(),
        'created_by' => $user->id,
    ]);

    $websiteHealthyProject = Project::factory()->create(['name' => 'Website Healthy App', 'created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $websiteHealthyProject->id,
        'uptime_check' => true,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);

    $missingHeartbeatProject = Project::factory()->create(['name' => 'Missing Heartbeat App', 'created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $missingHeartbeatProject->id,
        'current_status' => 'healthy',
        'last_heartbeat_at' => null,
        'created_by' => $user->id,
    ]);

    $disabledDangerProject = Project::factory()->create(['name' => 'Disabled Danger App', 'created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $disabledDangerProject->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    MonitorApis::factory()->disabled()->create([
        'project_id' => $disabledDangerProject->id,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    $uncheckedWebsiteProject = Project::factory()->create(['name' => 'Unchecked Website App', 'created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $uncheckedWebsiteProject->id,
        'uptime_check' => true,
        'current_status' => null,
        'created_by' => $user->id,
    ]);

    $unknownStatusApiProject = Project::factory()->create(['name' => 'Unknown Status API App', 'created_by' => $user->id]);
    MonitorApis::factory()->create([
        'project_id' => $unknownStatusApiProject->id,
        'is_enabled' => true,
        'current_status' => 'unknown',
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjects::class)
        ->filterTable('application_status', 'danger')
        ->assertCanSeeTableRecords([$dangerProject, $derivedDangerProject, $websiteDangerProject, $sslOnlyDangerProject])
        ->assertCanNotSeeTableRecords([$healthyProject, $warningProject, $storedDangerHealthyProject, $staleComponentProject, $apiWarningProject, $websiteHealthyProject, $staleWebsiteProject, $missingHeartbeatProject, $unknownProject, $disabledDangerProject, $uncheckedWebsiteProject, $staleApiProject, $unknownStatusApiProject]);

    Livewire::test(ListProjects::class)
        ->filterTable('application_status', 'warning')
        ->assertCanSeeTableRecords([$warningProject, $apiWarningProject])
        ->assertCanNotSeeTableRecords([$healthyProject, $dangerProject, $derivedDangerProject, $storedDangerHealthyProject, $staleComponentProject, $websiteDangerProject, $staleWebsiteProject, $sslOnlyDangerProject, $staleApiProject, $websiteHealthyProject, $missingHeartbeatProject, $unknownProject, $disabledDangerProject, $uncheckedWebsiteProject, $unknownStatusApiProject]);

    Livewire::test(ListProjects::class)
        ->filterTable('application_status', 'healthy')
        ->assertCanSeeTableRecords([$healthyProject, $storedDangerHealthyProject, $staleComponentProject, $websiteHealthyProject, $staleWebsiteProject, $staleApiProject])
        ->assertCanNotSeeTableRecords([$warningProject, $dangerProject, $derivedDangerProject, $websiteDangerProject, $sslOnlyDangerProject, $apiWarningProject, $missingHeartbeatProject, $unknownProject, $disabledDangerProject, $uncheckedWebsiteProject, $unknownStatusApiProject]);

    Livewire::test(ListProjects::class)
        ->filterTable('application_status', 'unknown')
        ->assertCanSeeTableRecords([$missingHeartbeatProject, $unknownProject, $disabledDangerProject, $uncheckedWebsiteProject, $unknownStatusApiProject])
        ->assertCanNotSeeTableRecords([$healthyProject, $warningProject, $dangerProject, $derivedDangerProject, $storedDangerHealthyProject, $staleComponentProject, $websiteDangerProject, $staleWebsiteProject, $sslOnlyDangerProject, $apiWarningProject, $staleApiProject, $websiteHealthyProject]);
});

test('application status rolls up enabled websites and api monitors', function () {
    $user = User::factory()->create();

    $healthyProject = Project::factory()->create(['created_by' => $user->id]);
    componentWithActiveApi($healthyProject, $user, 'healthy');

    $websiteDangerProject = Project::factory()->create(['created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $websiteDangerProject->id,
        'created_by' => $user->id,
        'uptime_check' => true,
        'current_status' => 'danger',
    ]);

    $sslOnlyWarningProject = Project::factory()->create(['created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $sslOnlyWarningProject->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => true,
        'current_status' => 'warning',
    ]);

    $apiWarningProject = Project::factory()->create(['created_by' => $user->id]);
    MonitorApis::factory()->create([
        'project_id' => $apiWarningProject->id,
        'created_by' => $user->id,
        'is_enabled' => true,
        'current_status' => 'warning',
    ]);

    $staleComponentProject = Project::factory()->create(['created_by' => $user->id]);
    componentWithActiveApi($staleComponentProject, $user, 'healthy', [
        'is_stale' => true,
        'stale_detected_at' => now()->subMinute(),
    ]);

    $staleWebsiteProject = Project::factory()->create(['created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $staleWebsiteProject->id,
        'created_by' => $user->id,
        'uptime_check' => true,
        'current_status' => 'healthy',
        'stale_at' => now()->subMinute(),
    ]);

    $staleApiProject = Project::factory()->create(['created_by' => $user->id]);
    MonitorApis::factory()->create([
        'project_id' => $staleApiProject->id,
        'created_by' => $user->id,
        'is_enabled' => true,
        'current_status' => 'healthy',
        'stale_at' => now()->subMinute(),
    ]);

    $disabledDangerProject = Project::factory()->create(['created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $disabledDangerProject->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'current_status' => 'danger',
    ]);
    MonitorApis::factory()->disabled()->create([
        'project_id' => $disabledDangerProject->id,
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    $uncheckedWebsiteProject = Project::factory()->create(['created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $uncheckedWebsiteProject->id,
        'created_by' => $user->id,
        'uptime_check' => true,
        'current_status' => null,
    ]);

    $missingHeartbeatProject = Project::factory()->create(['created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $missingHeartbeatProject->id,
        'created_by' => $user->id,
        'current_status' => 'healthy',
        'last_heartbeat_at' => null,
    ]);

    $unknownStatusApiProject = Project::factory()->create(['created_by' => $user->id]);
    MonitorApis::factory()->create([
        'project_id' => $unknownStatusApiProject->id,
        'created_by' => $user->id,
        'is_enabled' => true,
        'current_status' => 'unknown',
    ]);

    $mixedSurfaceProject = Project::factory()->create(['created_by' => $user->id]);
    componentWithActiveApi($mixedSurfaceProject, $user, 'healthy');
    Website::factory()->create([
        'project_id' => $mixedSurfaceProject->id,
        'created_by' => $user->id,
        'uptime_check' => true,
        'current_status' => 'danger',
    ]);

    expect($healthyProject->fresh()->application_status)->toBe('healthy')
        ->and($websiteDangerProject->fresh()->application_status)->toBe('danger')
        ->and($sslOnlyWarningProject->fresh()->application_status)->toBe('warning')
        ->and($apiWarningProject->fresh()->application_status)->toBe('warning')
        ->and($staleComponentProject->fresh()->application_status)->toBe('healthy')
        ->and($staleWebsiteProject->fresh()->application_status)->toBe('healthy')
        ->and($staleApiProject->fresh()->application_status)->toBe('healthy')
        ->and($disabledDangerProject->fresh()->application_status)->toBe('unknown')
        ->and($uncheckedWebsiteProject->fresh()->application_status)->toBe('pending')
        ->and($missingHeartbeatProject->fresh()->application_status)->toBe('pending')
        ->and($unknownStatusApiProject->fresh()->application_status)->toBe('pending')
        ->and($mixedSurfaceProject->fresh()->application_status)->toBe('danger');
});

test('component derived summary ignores stale awaiting text once child checks report', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $component = componentWithActiveApi($project, $user, 'danger', [
        'summary' => 'Awaiting active child check results',
    ]);

    expect($component->fresh()->derivedStatusSummary())->toBe('At least one active child check is failing.');
});

test('component derived status preserves standalone component health without child checks', function () {
    $component = ProjectComponent::factory()->create([
        'source' => 'proxy_pool',
        'current_status' => 'warning',
        'last_reported_status' => 'warning',
    ]);

    expect($component->fresh()->derivedCurrentStatus())->toBe('warning');
});

test('super admin can filter applications to only failing', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();

    $healthyProject = Project::factory()->create(['name' => 'Healthy App', 'created_by' => $user->id]);
    componentWithActiveApi($healthyProject, $user, 'healthy');

    $warningProject = Project::factory()->create(['name' => 'Warning App', 'created_by' => $user->id]);
    componentWithActiveApi($warningProject, $user, 'warning');

    $dangerProject = Project::factory()->create(['name' => 'Danger App', 'created_by' => $user->id]);
    componentWithActiveApi($dangerProject, $user, 'danger');

    $staleComponentProject = Project::factory()->create(['name' => 'Stale Component App', 'created_by' => $user->id]);
    componentWithActiveApi($staleComponentProject, $user, 'healthy', [
        'is_stale' => true,
        'stale_detected_at' => now()->subMinute(),
    ]);

    $unknownProject = Project::factory()->create(['name' => 'Unknown App', 'created_by' => $user->id]);

    $archivedFailingProject = Project::factory()->create(['name' => 'Archived Failing App', 'created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $archivedFailingProject->id,
        'current_status' => 'danger',
        'is_archived' => true,
        'archived_at' => now()->subHour(),
        'created_by' => $user->id,
    ]);

    $websiteDangerProject = Project::factory()->create(['name' => 'Website Danger App', 'created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $websiteDangerProject->id,
        'uptime_check' => true,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    $sslOnlyDangerProject = Project::factory()->create(['name' => 'SSL Danger App', 'created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $sslOnlyDangerProject->id,
        'uptime_check' => false,
        'ssl_check' => true,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    $apiWarningProject = Project::factory()->create(['name' => 'API Warning App', 'created_by' => $user->id]);
    MonitorApis::factory()->create([
        'project_id' => $apiWarningProject->id,
        'is_enabled' => true,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);

    $disabledFailingProject = Project::factory()->create(['name' => 'Disabled Failing App', 'created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $disabledFailingProject->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    MonitorApis::factory()->disabled()->create([
        'project_id' => $disabledFailingProject->id,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjects::class)
        ->filterTable('only_failing', true)
        ->assertCanSeeTableRecords([$warningProject, $dangerProject, $websiteDangerProject, $sslOnlyDangerProject, $apiWarningProject])
        ->assertCanNotSeeTableRecords([$healthyProject, $staleComponentProject, $unknownProject, $archivedFailingProject, $disabledFailingProject]);
});

test('super admin can filter application components by current status', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $healthy = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    $warning = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);
    $danger = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->filterTable('current_status', 'danger')
        ->assertCanSeeTableRecords([$danger])
        ->assertCanNotSeeTableRecords([$healthy, $warning]);

    Livewire::test(ListProjectComponents::class)
        ->filterTable('current_status', 'warning')
        ->assertCanSeeTableRecords([$warning])
        ->assertCanNotSeeTableRecords([$healthy, $danger]);

    Livewire::test(ListProjectComponents::class)
        ->filterTable('current_status', 'healthy')
        ->assertCanSeeTableRecords([$healthy])
        ->assertCanNotSeeTableRecords([$warning, $danger]);
});

test('super admin can filter application components by delivery state', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $pending = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'new-worker',
        'is_archived' => false,
        'created_by' => $user->id,
    ]);
    $active = componentWithActiveApi($project, $user, 'healthy', [
        'name' => 'database',
        'is_archived' => false,
    ]);
    $snoozed = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'muted-cache',
        'is_archived' => false,
        'silenced_until' => now()->addHour(),
        'created_by' => $user->id,
    ]);
    $archived = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'name' => 'retired-proxy',
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->assertSee('Delivery State')
        ->assertSee('Snoozed')
        ->assertSee('Pending')
        ->assertSee('Active')
        ->assertSee('Archived');

    Livewire::test(ListProjectComponents::class)
        ->filterTable('delivery_state', 'snoozed')
        ->assertCanSeeTableRecords([$snoozed])
        ->assertCanNotSeeTableRecords([$pending, $active, $archived]);

    Livewire::test(ListProjectComponents::class)
        ->filterTable('delivery_state', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$snoozed, $active, $archived]);

    Livewire::test(ListProjectComponents::class)
        ->filterTable('delivery_state', 'active')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$snoozed, $pending, $archived]);

    Livewire::test(ListProjectComponents::class)
        ->filterTable('delivery_state', 'archived')
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$snoozed, $pending, $active]);
});

test('super admin can filter project components relation manager by delivery state', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $pending = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'is_archived' => false,
        'created_by' => $user->id,
    ]);
    $active = componentWithActiveApi($project, $user, 'healthy', [
        'is_archived' => false,
    ]);
    $snoozed = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'is_archived' => false,
        'silenced_until' => now()->addHour(),
        'created_by' => $user->id,
    ]);
    $archived = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertSee('Delivery State')
        ->assertSee('Snoozed')
        ->assertSee('Pending')
        ->assertSee('Active')
        ->assertSee('Archived');

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('delivery_state', 'snoozed')
        ->assertCanSeeTableRecords([$snoozed])
        ->assertCanNotSeeTableRecords([$pending, $active, $archived]);

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('delivery_state', 'pending')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$snoozed, $active, $archived]);

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('delivery_state', 'active')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$snoozed, $pending, $archived]);

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->filterTable('delivery_state', 'archived')
        ->assertCanSeeTableRecords([$archived])
        ->assertCanNotSeeTableRecords([$snoozed, $pending, $active]);
});

test('component tables show active failing api and website child counts', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'checkout',
        'created_by' => $user->id,
    ]);

    MonitorApis::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'is_enabled' => true,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    MonitorApis::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'is_enabled' => true,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);
    MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    Website::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    Website::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    Website::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertSee('Failing APIs')
        ->assertSee('Failing Websites')
        ->assertTableColumnStateSet('active_failing_monitor_apis_count', 2, $component)
        ->assertTableColumnStateSet('active_failing_websites_count', 1, $component);

    Livewire::test(ListProjectComponents::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('active_failing_monitor_apis_count', 2, $component)
        ->assertTableColumnStateSet('active_failing_websites_count', 1, $component);
});

test('component list default render derives status from counts without loading child rows', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);

    MonitorApis::factory()->count(3)->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'is_enabled' => true,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);

    Website::factory()->count(2)->create([
        'project_id' => $project->id,
        'project_component_id' => $component->id,
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);

    $sql = [];

    DB::listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    Livewire::test(ListProjectComponents::class)
        ->assertSuccessful()
        ->assertSee('Healthy');

    $joinedSql = implode("\n", $sql);

    expect($joinedSql)
        ->not->toContain('project_component_id" in')
        ->not->toContain('project_component_id` in');
});

test('package-managed relation managers skip failure streak aggregates for healthy latest checks', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $website = Website::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'status' => 'healthy',
        'http_status_code' => 200,
    ]);

    $api = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'source' => 'package',
        'is_enabled' => true,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    MonitorApiResult::factory()->successful()->create([
        'monitor_api_id' => $api->id,
    ]);

    $sql = [];

    DB::listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->assertSuccessful();

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->assertSuccessful();

    expect(implode("\n", $sql))->not->toContain('streak_count');
});

test('package-managed child relation managers filter by component', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $checkout = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'checkout',
        'created_by' => $user->id,
    ]);
    $billing = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'billing',
        'created_by' => $user->id,
    ]);

    $checkoutWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $checkout->id,
        'name' => 'checkout-homepage',
        'source' => 'package',
        'package_name' => 'checkout-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    $billingWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $billing->id,
        'name' => 'billing-homepage',
        'source' => 'package',
        'package_name' => 'billing-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    $unmappedWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => null,
        'name' => 'unmapped-homepage',
        'source' => 'package',
        'package_name' => 'unmapped-homepage',
        'uptime_check' => true,
        'ssl_check' => false,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    $checkoutApi = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $checkout->id,
        'title' => 'checkout-api',
        'source' => 'package',
        'package_name' => 'checkout-api',
        'is_enabled' => true,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    $billingApi = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => $billing->id,
        'title' => 'billing-api',
        'source' => 'package',
        'package_name' => 'billing-api',
        'is_enabled' => true,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    $unmappedApi = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => null,
        'title' => 'unmapped-api',
        'source' => 'package',
        'package_name' => 'unmapped-api',
        'is_enabled' => true,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertSee('Component')
        ->filterTable('project_component_id', $checkout->id)
        ->assertCanSeeTableRecords([$checkoutWebsite])
        ->assertCanNotSeeTableRecords([$billingWebsite, $unmappedWebsite]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertSee('Unmapped')
        ->filterTable('project_component_id', '__unmapped')
        ->assertCanSeeTableRecords([$unmappedWebsite])
        ->assertCanNotSeeTableRecords([$checkoutWebsite, $billingWebsite]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertSee('Component')
        ->filterTable('project_component_id', $checkout->id)
        ->assertCanSeeTableRecords([$checkoutApi])
        ->assertCanNotSeeTableRecords([$billingApi, $unmappedApi]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertSee('Unmapped')
        ->filterTable('project_component_id', '__unmapped')
        ->assertCanSeeTableRecords([$unmappedApi])
        ->assertCanNotSeeTableRecords([$checkoutApi, $billingApi]);
});

test('package-managed website checks can be bulk assigned to an existing or new component', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $checkout = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'checkout',
        'created_by' => $user->id,
    ]);

    $homepage = Website::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => null,
        'name' => 'homepage',
        'source' => 'package',
        'package_name' => 'homepage',
        'created_by' => $user->id,
    ]);
    $status = Website::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => null,
        'name' => 'status',
        'source' => 'package',
        'package_name' => 'status',
        'created_by' => $user->id,
    ]);

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableBulkActionExists('assign_component')
        ->assertTableBulkActionHasLabel('assign_component', 'Assign component')
        ->callTableBulkAction('assign_component', collect([$homepage]), data: [
            'project_component_id' => $checkout->id,
            'component_name' => null,
        ])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('1 website assigned');

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableBulkAction('assign_component', collect([$status]), data: [
            'project_component_id' => null,
            'component_name' => 'status-page',
        ])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('1 website assigned');

    $statusComponent = ProjectComponent::query()->where('project_id', $project->id)->where('name', 'status-page')->sole();

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableBulkAction('assign_component', collect([$homepage]), data: [
            'project_component_id' => $checkout->id,
            'component_name' => null,
        ])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('No websites changed');

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableBulkAction('assign_component', collect([$homepage]), data: [
            'project_component_id' => $checkout->id,
            'component_name' => 'ambiguous',
        ])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('Choose one component option');

    expect($homepage->refresh()->project_component_id)->toBe($checkout->id)
        ->and($status->refresh()->project_component_id)->toBe($statusComponent->id)
        ->and($statusComponent->source)->toBe('manual')
        ->and($statusComponent->current_status)->toBe('unknown')
        ->and($statusComponent->summary)->toBe('Awaiting active child check results')
        ->and(ProjectComponent::query()->where('project_id', $project->id)->where('name', 'ambiguous')->exists())->toBeFalse();
});

test('package-managed api checks can be bulk assigned to an existing or new component', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $checkout = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'checkout',
        'created_by' => $user->id,
    ]);

    $health = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => null,
        'title' => 'health',
        'source' => 'package',
        'package_name' => 'health',
        'created_by' => $user->id,
    ]);
    $orders = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'project_component_id' => null,
        'title' => 'orders',
        'source' => 'package',
        'package_name' => 'orders',
        'created_by' => $user->id,
    ]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableBulkActionExists('assign_component')
        ->assertTableBulkActionHasLabel('assign_component', 'Assign component')
        ->callTableBulkAction('assign_component', collect([$health]), data: [
            'project_component_id' => $checkout->id,
            'component_name' => null,
        ])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('1 API assigned');

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableBulkAction('assign_component', collect([$orders]), data: [
            'project_component_id' => null,
            'component_name' => 'orders-api',
        ])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('1 API assigned');

    $ordersComponent = ProjectComponent::query()->where('project_id', $project->id)->where('name', 'orders-api')->sole();

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableBulkAction('assign_component', collect([$health]), data: [
            'project_component_id' => $checkout->id,
            'component_name' => null,
        ])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('No APIs changed');

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableBulkAction('assign_component', collect([$health]), data: [
            'project_component_id' => $checkout->id,
            'component_name' => 'ambiguous',
        ])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('Choose one component option');

    expect($health->refresh()->project_component_id)->toBe($checkout->id)
        ->and($orders->refresh()->project_component_id)->toBe($ordersComponent->id)
        ->and($ordersComponent->source)->toBe('manual')
        ->and($ordersComponent->current_status)->toBe('unknown')
        ->and($ordersComponent->summary)->toBe('Awaiting active child check results')
        ->and(ProjectComponent::query()->where('project_id', $project->id)->where('name', 'ambiguous')->exists())->toBeFalse();
});

test('super admin can filter application components to only failing', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $healthy = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
    $warning = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);
    $danger = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);
    $archivedWarning = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);
    $archivedDanger = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->filterTable('only_failing', true)
        ->assertCanSeeTableRecords([$warning, $danger])
        ->assertCanNotSeeTableRecords([$healthy, $archivedWarning, $archivedDanger]);
});

test('super admin can snooze application component from application detail', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableAction('snooze', $component, data: [
            'duration' => '1h',
        ])
        ->assertHasNoTableActionErrors();

    expect($component->refresh()->silenced_until)->not->toBeNull()
        ->and($component->silenced_until->isFuture())->toBeTrue();
});

test('super admin can unsnooze application component from application detail', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'silenced_until' => now()->addHour(),
    ]);

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableAction('unsnooze', $component)
        ->assertHasNoTableActionErrors()
        ->assertNotified('Notifications resumed');

    expect($component->refresh()->silenced_until)->toBeNull();
});

test('super admin can bulk unsnooze application components from application detail', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $components = ProjectComponent::factory()->count(2)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'silenced_until' => now()->addHour(),
    ]);

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableBulkAction('unsnooze', $components)
        ->assertNotified('2 components unsnoozed');

    foreach ($components as $component) {
        expect($component->refresh()->silenced_until)->toBeNull();
    }
});

test('super admin can bulk disable and enable application components from application detail', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $components = ProjectComponent::factory()->count(2)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_archived' => false,
        'current_status' => 'danger',
        'last_reported_status' => 'danger',
        'summary' => 'Heartbeat expired',
        'last_heartbeat_at' => now()->subMinutes(15),
        'is_stale' => true,
        'stale_detected_at' => now()->subMinutes(5),
    ]);

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableBulkAction('disable', $components)
        ->assertNotified('2 components disabled');

    foreach ($components as $component) {
        $component->refresh();

        expect($component->is_archived)->toBeTrue()
            ->and($component->current_status)->toBe('unknown')
            ->and($component->summary)->toBe(ProjectComponent::ADMIN_DISABLED_SUMMARY);
    }

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->callTableBulkAction('enable', $components)
        ->assertNotified('2 components enabled');

    foreach ($components as $component) {
        $component->refresh();

        expect($component->is_archived)->toBeFalse()
            ->and($component->archived_at)->toBeNull();
    }
});

test('admin without Update:ProjectComponent cannot see application component management actions', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo([
        'ViewAny:Project',
        'View:Project',
        'ViewAny:ProjectComponent',
        'View:ProjectComponent',
    ]);
    $this->actingAs($user);

    $project = Project::factory()->create(['created_by' => $user->id]);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'silenced_until' => now()->addHour(),
    ]);

    Livewire::test(ComponentsRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertTableActionHidden('snooze')
        ->assertTableActionHidden('unsnooze', $component)
        ->assertTableBulkActionHidden('snooze')
        ->assertTableBulkActionHidden('unsnooze')
        ->assertTableBulkActionHidden('enable')
        ->assertTableBulkActionHidden('disable');
});

test('super admin can bulk disable project components', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $components = ProjectComponent::factory()->count(3)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_archived' => false,
        'current_status' => 'danger',
        'last_reported_status' => 'danger',
        'summary' => 'Heartbeat expired',
        'last_heartbeat_at' => now()->subMinutes(15),
        'is_stale' => true,
        'stale_detected_at' => now()->subMinutes(5),
    ]);

    Livewire::test(ListProjectComponents::class)
        ->callTableBulkAction('disable', $components);

    foreach ($components as $component) {
        $component->refresh();
        expect($component->is_archived)->toBeTrue()
            ->and($component->current_status)->toBe('unknown')
            ->and($component->last_reported_status)->toBe('unknown')
            ->and($component->summary)->toBe(ProjectComponent::ADMIN_DISABLED_SUMMARY)
            ->and($component->last_heartbeat_at)->toBeNull()
            ->and($component->is_stale)->toBeNull()
            ->and($component->stale_detected_at)->toBeNull()
            ->and($component->archived_at)->not->toBeNull();
    }
});

test('super admin can bulk enable project components', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $components = ProjectComponent::factory()->archived()->count(3)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->callTableBulkAction('enable', $components);

    foreach ($components as $component) {
        $component->refresh();
        expect($component->is_archived)->toBeFalse()
            ->and($component->archived_at)->toBeNull();
    }
});

test('super admin can bulk pause monitoring across selected applications', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $project = Project::factory()->create(['created_by' => $user->id]);

    $website = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => true,
        'ssl_check' => true,
        'outbound_check' => true,
        'current_status' => 'danger',
        'last_heartbeat_at' => now()->subMinutes(15),
        'stale_at' => now()->subMinutes(5),
        'status_summary' => 'Website heartbeat failed with HTTP status 500.',
        'diagnostic_queued_at' => now()->subMinute(),
    ]);

    $monitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_enabled' => true,
        'current_status' => 'danger',
        'last_heartbeat_at' => now()->subMinutes(15),
        'stale_at' => now()->subMinutes(5),
        'status_summary' => 'API check failed with HTTP status 500.',
        'diagnostic_queued_at' => now()->subMinute(),
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_archived' => false,
        'current_status' => 'danger',
        'last_reported_status' => 'danger',
        'summary' => 'Heartbeat expired',
        'last_heartbeat_at' => now()->subMinutes(15),
        'is_stale' => true,
        'stale_detected_at' => now()->subMinutes(5),
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('disable', collect([$project]));

    expect($website->refresh()->uptime_check)->toBeFalse()
        ->and($website->ssl_check)->toBeFalse()
        ->and($website->outbound_check)->toBeFalse()
        ->and($website->project_paused_uptime_check)->toBeTrue()
        ->and($website->project_paused_ssl_check)->toBeTrue()
        ->and($website->project_paused_outbound_check)->toBeTrue()
        ->and($website->current_status)->toBe('unknown')
        ->and($website->last_heartbeat_at)->toBeNull()
        ->and($website->stale_at)->toBeNull()
        ->and($website->status_summary)->toBe(Website::ADMIN_DISABLED_STATUS_SUMMARY)
        ->and($website->diagnostic_queued_at)->toBeNull()
        ->and($monitor->refresh()->is_enabled)->toBeFalse()
        ->and($monitor->project_paused_monitoring)->toBeTrue()
        ->and($monitor->current_status)->toBe('unknown')
        ->and($monitor->last_heartbeat_at)->toBeNull()
        ->and($monitor->stale_at)->toBeNull()
        ->and($monitor->status_summary)->toBe(MonitorApis::ADMIN_DISABLED_STATUS_SUMMARY)
        ->and($monitor->diagnostic_queued_at)->toBeNull()
        ->and($component->refresh()->is_archived)->toBeTrue()
        ->and($component->project_paused_monitoring)->toBeTrue()
        ->and($component->current_status)->toBe('unknown')
        ->and($component->last_reported_status)->toBe('unknown')
        ->and($component->summary)->toBe(ProjectComponent::ADMIN_DISABLED_SUMMARY)
        ->and($component->last_heartbeat_at)->toBeNull()
        ->and($component->is_stale)->toBeNull()
        ->and($component->stale_detected_at)->toBeNull();
});

test('super admin can bulk resume monitoring across selected applications', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();

    $project = Project::factory()->create(['created_by' => $user->id]);

    $website = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'project_paused_uptime_check' => true,
        'ssl_check' => false,
        'project_paused_ssl_check' => true,
        'outbound_check' => false,
        'project_paused_outbound_check' => true,
    ]);

    $monitor = MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'project_paused_monitoring' => true,
    ]);

    $component = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'project_paused_monitoring' => true,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($website->refresh()->uptime_check)->toBeTrue()
        ->and($website->ssl_check)->toBeTrue()
        ->and($website->outbound_check)->toBeTrue()
        ->and($website->project_paused_uptime_check)->toBeFalse()
        ->and($website->project_paused_ssl_check)->toBeFalse()
        ->and($website->project_paused_outbound_check)->toBeFalse()
        ->and($monitor->refresh()->is_enabled)->toBeTrue()
        ->and($monitor->project_paused_monitoring)->toBeFalse()
        ->and($component->refresh()->is_archived)->toBeFalse()
        ->and($component->project_paused_monitoring)->toBeFalse();
});

test('bulk resume restores only website checks paused by the project action', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $bothChecks = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => true,
        'ssl_check' => true,
        'outbound_check' => true,
    ]);

    $uptimeOnly = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => true,
        'ssl_check' => false,
        'outbound_check' => false,
    ]);

    $sslOnly = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => true,
        'outbound_check' => false,
    ]);

    $outboundOnly = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'outbound_check' => true,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('disable', collect([$project]));

    expect($bothChecks->refresh()->uptime_check)->toBeFalse()
        ->and($bothChecks->ssl_check)->toBeFalse()
        ->and($bothChecks->outbound_check)->toBeFalse()
        ->and($bothChecks->project_paused_uptime_check)->toBeTrue()
        ->and($bothChecks->project_paused_ssl_check)->toBeTrue()
        ->and($bothChecks->project_paused_outbound_check)->toBeTrue()
        ->and($uptimeOnly->refresh()->uptime_check)->toBeFalse()
        ->and($uptimeOnly->ssl_check)->toBeFalse()
        ->and($uptimeOnly->outbound_check)->toBeFalse()
        ->and($uptimeOnly->project_paused_uptime_check)->toBeTrue()
        ->and($uptimeOnly->project_paused_ssl_check)->toBeFalse()
        ->and($uptimeOnly->project_paused_outbound_check)->toBeFalse()
        ->and($sslOnly->refresh()->uptime_check)->toBeFalse()
        ->and($sslOnly->ssl_check)->toBeFalse()
        ->and($sslOnly->outbound_check)->toBeFalse()
        ->and($sslOnly->project_paused_uptime_check)->toBeFalse()
        ->and($sslOnly->project_paused_ssl_check)->toBeTrue()
        ->and($sslOnly->project_paused_outbound_check)->toBeFalse()
        ->and($outboundOnly->refresh()->uptime_check)->toBeFalse()
        ->and($outboundOnly->ssl_check)->toBeFalse()
        ->and($outboundOnly->outbound_check)->toBeFalse()
        ->and($outboundOnly->project_paused_uptime_check)->toBeFalse()
        ->and($outboundOnly->project_paused_ssl_check)->toBeFalse()
        ->and($outboundOnly->project_paused_outbound_check)->toBeTrue();

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($bothChecks->refresh()->uptime_check)->toBeTrue()
        ->and($bothChecks->ssl_check)->toBeTrue()
        ->and($bothChecks->outbound_check)->toBeTrue()
        ->and($bothChecks->project_paused_uptime_check)->toBeFalse()
        ->and($bothChecks->project_paused_ssl_check)->toBeFalse()
        ->and($bothChecks->project_paused_outbound_check)->toBeFalse()
        ->and($uptimeOnly->refresh()->uptime_check)->toBeTrue()
        ->and($uptimeOnly->ssl_check)->toBeFalse()
        ->and($uptimeOnly->outbound_check)->toBeFalse()
        ->and($uptimeOnly->project_paused_uptime_check)->toBeFalse()
        ->and($uptimeOnly->project_paused_ssl_check)->toBeFalse()
        ->and($uptimeOnly->project_paused_outbound_check)->toBeFalse()
        ->and($sslOnly->refresh()->uptime_check)->toBeFalse()
        ->and($sslOnly->ssl_check)->toBeTrue()
        ->and($sslOnly->outbound_check)->toBeFalse()
        ->and($sslOnly->project_paused_uptime_check)->toBeFalse()
        ->and($sslOnly->project_paused_ssl_check)->toBeFalse()
        ->and($sslOnly->project_paused_outbound_check)->toBeFalse()
        ->and($outboundOnly->refresh()->uptime_check)->toBeFalse()
        ->and($outboundOnly->ssl_check)->toBeFalse()
        ->and($outboundOnly->outbound_check)->toBeTrue()
        ->and($outboundOnly->project_paused_uptime_check)->toBeFalse()
        ->and($outboundOnly->project_paused_ssl_check)->toBeFalse()
        ->and($outboundOnly->project_paused_outbound_check)->toBeFalse();
});

test('bulk resume respects outbound checks manually disabled while project is paused', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $website = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => true,
        'ssl_check' => true,
        'outbound_check' => true,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('disable', collect([$project]));

    expect($website->refresh()->outbound_check)->toBeFalse()
        ->and($website->project_paused_uptime_check)->toBeTrue()
        ->and($website->project_paused_ssl_check)->toBeTrue()
        ->and($website->project_paused_outbound_check)->toBeTrue();

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->fillForm(['outbound_check' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($website->refresh()->outbound_check)->toBeTrue()
        ->and($website->project_paused_outbound_check)->toBeTrue();

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->fillForm(['outbound_check' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($website->refresh()->outbound_check)->toBeFalse()
        ->and($website->project_paused_uptime_check)->toBeTrue()
        ->and($website->project_paused_ssl_check)->toBeTrue()
        ->and($website->project_paused_outbound_check)->toBeFalse();

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($website->refresh()->uptime_check)->toBeTrue()
        ->and($website->ssl_check)->toBeTrue()
        ->and($website->outbound_check)->toBeFalse()
        ->and($website->project_paused_uptime_check)->toBeFalse()
        ->and($website->project_paused_ssl_check)->toBeFalse()
        ->and($website->project_paused_outbound_check)->toBeFalse();
});

test('bulk resume does not restore fully disabled websites without project pause flags', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $legacyPaused = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'outbound_check' => false,
        'project_paused_uptime_check' => false,
        'project_paused_ssl_check' => false,
        'project_paused_outbound_check' => false,
    ]);

    $legacyPausedWithOutboundEnabled = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'outbound_check' => true,
        'project_paused_uptime_check' => false,
        'project_paused_ssl_check' => false,
        'project_paused_outbound_check' => false,
    ]);

    $sslOnly = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => true,
        'outbound_check' => false,
        'project_paused_uptime_check' => false,
        'project_paused_ssl_check' => false,
        'project_paused_outbound_check' => false,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($legacyPaused->refresh()->uptime_check)->toBeFalse()
        ->and($legacyPaused->ssl_check)->toBeFalse()
        ->and($legacyPaused->outbound_check)->toBeFalse()
        ->and($legacyPaused->project_paused_uptime_check)->toBeFalse()
        ->and($legacyPaused->project_paused_ssl_check)->toBeFalse()
        ->and($legacyPaused->project_paused_outbound_check)->toBeFalse()
        ->and($legacyPausedWithOutboundEnabled->refresh()->uptime_check)->toBeFalse()
        ->and($legacyPausedWithOutboundEnabled->ssl_check)->toBeFalse()
        ->and($legacyPausedWithOutboundEnabled->outbound_check)->toBeTrue()
        ->and($legacyPausedWithOutboundEnabled->project_paused_uptime_check)->toBeFalse()
        ->and($legacyPausedWithOutboundEnabled->project_paused_ssl_check)->toBeFalse()
        ->and($legacyPausedWithOutboundEnabled->project_paused_outbound_check)->toBeFalse()
        ->and($sslOnly->refresh()->uptime_check)->toBeFalse()
        ->and($sslOnly->ssl_check)->toBeTrue()
        ->and($sslOnly->outbound_check)->toBeFalse()
        ->and($sslOnly->project_paused_uptime_check)->toBeFalse()
        ->and($sslOnly->project_paused_ssl_check)->toBeFalse()
        ->and($sslOnly->project_paused_outbound_check)->toBeFalse();
});

test('bulk resume restores only api monitors disabled by the project action', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $projectPaused = MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'project_paused_monitoring' => true,
    ]);

    $manuallyDisabled = MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'project_paused_monitoring' => false,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($projectPaused->refresh()->is_enabled)->toBeTrue()
        ->and($projectPaused->project_paused_monitoring)->toBeFalse()
        ->and($manuallyDisabled->refresh()->is_enabled)->toBeFalse()
        ->and($manuallyDisabled->project_paused_monitoring)->toBeFalse();
});

test('editing a project-paused api monitor does not clear its resume marker', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $monitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('disable', collect([$project]));

    expect($monitor->refresh()->is_enabled)->toBeFalse()
        ->and($monitor->project_paused_monitoring)->toBeTrue();

    Livewire::test(EditMonitorApis::class, ['record' => $monitor->id])
        ->fillForm([
            'title' => $monitor->title,
            'url' => $monitor->url,
            'data_path' => 'data.changed',
            'project_id' => $project->id,
            'package_interval' => $monitor->package_interval ?? '5m',
            'is_enabled' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($monitor->refresh()->is_enabled)->toBeFalse()
        ->and($monitor->data_path)->toBe('data.changed')
        ->and($monitor->project_paused_monitoring)->toBeTrue();

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($monitor->refresh()->is_enabled)->toBeTrue()
        ->and($monitor->project_paused_monitoring)->toBeFalse();
});

test('bulk resume restores only components archived by the project action', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $projectPaused = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'project_paused_monitoring' => true,
    ]);

    $manuallyArchived = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'project_paused_monitoring' => false,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($projectPaused->refresh()->is_archived)->toBeFalse()
        ->and($projectPaused->project_paused_monitoring)->toBeFalse()
        ->and($manuallyArchived->refresh()->is_archived)->toBeTrue()
        ->and($manuallyArchived->project_paused_monitoring)->toBeFalse();
});

test('editing a project-paused component does not clear its resume marker', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_archived' => false,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('disable', collect([$project]));

    expect($component->refresh()->is_archived)->toBeTrue()
        ->and($component->project_paused_monitoring)->toBeTrue();

    $originalName = $component->name;

    Livewire::test(EditProjectComponent::class, ['record' => $component->id])
        ->fillForm([
            'project_id' => $project->id,
            'name' => 'renamed-'.$originalName,
            'declared_interval' => $component->declared_interval,
            'current_status' => $component->current_status,
            'is_archived' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($component->refresh()->is_archived)->toBeTrue()
        ->and($component->name)->toBe('renamed-'.$originalName)
        ->and($component->project_paused_monitoring)->toBeTrue();

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($component->refresh()->is_archived)->toBeFalse()
        ->and($component->project_paused_monitoring)->toBeFalse();
});

test('bulk disable on already disabled components notifies that nothing changed', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $components = ProjectComponent::factory()->archived()->count(2)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->callTableBulkAction('disable', $components)
        ->assertNotified('Nothing to disable');

    foreach ($components as $component) {
        expect($component->refresh()->is_archived)->toBeTrue();
    }
});

test('bulk enable on already active components notifies that nothing changed', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $components = ProjectComponent::factory()->count(2)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_archived' => false,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->callTableBulkAction('enable', $components)
        ->assertNotified('Nothing to enable');

    foreach ($components as $component) {
        expect($component->refresh()->is_archived)->toBeFalse();
    }
});

test('bulk disable on applications with nothing to pause reports no changes', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $monitor = MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    $website = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => false,
        'outbound_check' => false,
    ]);

    $component = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('disable', collect([$project]))
        ->assertNotified('Nothing to disable');

    expect($monitor->refresh()->is_enabled)->toBeFalse()
        ->and($website->refresh()->uptime_check)->toBeFalse()
        ->and($website->ssl_check)->toBeFalse()
        ->and($website->outbound_check)->toBeFalse()
        ->and($component->refresh()->is_archived)->toBeTrue();
});

test('bulk enable on applications with nothing to resume reports no changes', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $monitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);

    $website = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => true,
        'ssl_check' => true,
        'outbound_check' => true,
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_archived' => false,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]))
        ->assertNotified('Nothing to enable');

    expect($monitor->refresh()->is_enabled)->toBeTrue()
        ->and($website->refresh()->uptime_check)->toBeTrue()
        ->and($website->ssl_check)->toBeTrue()
        ->and($website->outbound_check)->toBeTrue()
        ->and($component->refresh()->is_archived)->toBeFalse();
});

test('regular users only see their own applications and components', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($user);

    $ownProject = Project::factory()->create([
        'name' => 'Own App',
        'created_by' => $user->id,
    ]);

    $otherProject = Project::factory()->create([
        'name' => 'Other App',
        'created_by' => $otherUser->id,
    ]);

    $ownComponent = ProjectComponent::factory()->create([
        'project_id' => $ownProject->id,
        'name' => 'database',
        'created_by' => $user->id,
    ]);

    $otherComponent = ProjectComponent::factory()->create([
        'project_id' => $otherProject->id,
        'name' => 'queue',
        'created_by' => $otherUser->id,
    ]);

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$ownProject])
        ->assertCanNotSeeTableRecords([$otherProject]);

    Livewire::test(ListProjectComponents::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$ownComponent])
        ->assertCanNotSeeTableRecords([$otherComponent]);
});

test('admin without Update:Project permission cannot see project bulk actions', function () {
    $this->createResourcePermissions('Project');

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo('ViewAny:Project');
    $this->actingAs($user);

    Project::factory()->count(2)->create(['created_by' => $user->id]);

    Livewire::test(ListProjects::class)
        ->assertTableBulkActionHidden('enable')
        ->assertTableBulkActionHidden('disable');
});

test('admin with Update:Project but missing child update permissions cannot see cascade actions', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');
    $this->createResourcePermissions('MonitorApis');
    // Website permissions are already created in TestCase::setUp().

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo([
        'ViewAny:Project',
        'Update:Project',
        // Deliberately missing Update:Website, Update:MonitorApis, Update:ProjectComponent.
    ]);
    $this->actingAs($user);

    Project::factory()->count(2)->create(['created_by' => $user->id]);

    Livewire::test(ListProjects::class)
        ->assertTableBulkActionHidden('enable')
        ->assertTableBulkActionHidden('disable');
});

test('admin without Update:ProjectComponent permission cannot see component bulk actions', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo('ViewAny:ProjectComponent');
    $this->actingAs($user);

    $project = Project::factory()->create(['created_by' => $user->id]);
    ProjectComponent::factory()->count(2)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->assertTableBulkActionHidden('enable')
        ->assertTableBulkActionHidden('disable');
});

test('project component navigation badge shows plain total when everything is healthy', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    ProjectComponent::factory()->count(2)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);

    expect(\App\Filament\Resources\ProjectComponents\ProjectComponentResource::getNavigationBadge())->toBe('2')
        ->and(\App\Filament\Resources\ProjectComponents\ProjectComponentResource::getNavigationBadgeColor())->toBeNull();
});

test('project component navigation badge highlights unhealthy count in danger color', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'current_status' => 'warning',
    ]);
    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    expect(\App\Filament\Resources\ProjectComponents\ProjectComponentResource::getNavigationBadge())->toBe('2/3')
        ->and(\App\Filament\Resources\ProjectComponents\ProjectComponentResource::getNavigationBadgeColor())->toBe('danger');
});

test('project component navigation badge ignores archived unhealthy components', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    expect(ProjectComponentResource::getNavigationBadge())->toBe('2')
        ->and(ProjectComponentResource::getNavigationBadgeColor())->toBeNull();
});

test('project component navigation badge is scoped to the current user', function () {
    $user = $this->actingAsSuperAdmin();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $otherProject = Project::factory()->create(['created_by' => $otherUser->id]);

    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    ProjectComponent::factory()->create([
        'project_id' => $otherProject->id,
        'created_by' => $otherUser->id,
        'current_status' => 'danger',
    ]);

    expect(\App\Filament\Resources\ProjectComponents\ProjectComponentResource::getNavigationBadge())->toBe('1')
        ->and(\App\Filament\Resources\ProjectComponents\ProjectComponentResource::getNavigationBadgeColor())->toBeNull();
});

test('project component list shows empty state with create CTA when no components exist', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $this->actingAsSuperAdmin();

    Livewire::test(ListProjectComponents::class)
        ->assertSee('No application components yet')
        ->assertSee('Add a component and link active package checks to derive its health.')
        ->assertSee('Add component');
});
