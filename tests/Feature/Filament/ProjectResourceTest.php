<?php

use App\Filament\Resources\ProjectComponents\Pages\CreateProjectComponent;
use App\Filament\Resources\ProjectComponents\Pages\EditProjectComponent;
use App\Filament\Resources\ProjectComponents\Pages\ListProjectComponents;
use App\Filament\Resources\ProjectComponents\Pages\ViewProjectComponent;
use App\Filament\Resources\ProjectComponents\RelationManagers\HeartbeatsRelationManager;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\RelationManagers\PackageManagedApisRelationManager;
use App\Filament\Resources\Projects\RelationManagers\PackageManagedWebsitesRelationManager;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProjectComponentHeartbeat;
use App\Models\User;
use App\Models\Website;
use Livewire\Livewire;

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
            'current_status' => 'healthy',
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
        ->and($component->current_status)->toBe('healthy')
        ->and($component->last_reported_status)->toBe('healthy')
        ->and($component->metrics)->toBe([])
        ->and($component->is_archived)->toBeFalse()
        ->and($component->archived_at)->toBeNull();
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
        ->and($component->current_status)->toBe('warning')
        ->and($component->last_reported_status)->toBe('warning')
        ->and($component->is_archived)->toBeTrue()
        ->and($component->archived_at)->not->toBeNull();
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

    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $archivedComponent->id,
        'component_name' => 'legacy-proxy',
        'status' => 'danger',
        'event' => 'stale',
        'summary' => 'Heartbeat expired',
    ]);

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$project]);

    Livewire::test(ListProjectComponents::class)
        ->assertSuccessful()
        ->assertSee($activeComponent->name)
        ->assertSee($archivedComponent->name);
});

test('project component detail shows heartbeat history', function () {
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

    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $archivedComponent->id,
        'component_name' => 'legacy-proxy',
        'status' => 'danger',
        'event' => 'stale',
        'summary' => 'Heartbeat expired',
        'metrics' => [
            'queue_depth' => 17,
            'healthy' => false,
        ],
        'observed_at' => now()->subMinutes(4),
    ]);

    Livewire::test(ViewProjectComponent::class, ['record' => $archivedComponent->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Timing Evidence')
        ->assertSee('Current Metrics')
        ->assertSee('Stale Threshold')
        ->assertSee('Heartbeat expired')
        ->assertSee('queue_depth')
        ->assertSee('17')
        ->assertSee('stale');

    Livewire::test(HeartbeatsRelationManager::class, [
        'ownerRecord' => $archivedComponent,
        'pageClass' => ViewProjectComponent::class,
    ])
        ->assertSuccessful()
        ->assertSee('Metrics')
        ->assertSee('queue_depth')
        ->assertSee('17');
});

test('project component detail shows stale threshold with configured grace window', function () {
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
        ->assertSee('Stale Threshold')
        ->assertSee('Mon, Apr 27, 2026 12:01 PM')
        ->assertSee('Includes 2-minute grace')
        ->assertSee('Expires');

    $this->travelBack();
});

test('project component detail omits grace hint when stale grace is disabled', function () {
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
        ->assertSee('Stale Threshold')
        ->assertSee('Mon, Apr 27, 2026 11:59 AM')
        ->assertSee('Expired')
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

    ProjectComponent::factory()->create([
        'project_id' => $healthyProject->id,
        'name' => 'database',
        'current_status' => 'healthy',
        'created_by' => $user->id,
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

    ProjectComponent::factory()->create([
        'project_id' => $dangerProject->id,
        'name' => 'queue',
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);

    ProjectComponent::factory()->create([
        'project_id' => $dangerProject->id,
        'name' => 'database',
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjects::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$healthyProject, $dangerProject])
        ->assertSee('healthy')
        ->assertSee('danger');

    Livewire::test(ViewProject::class, ['record' => $healthyProject->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('healthy');

    Livewire::test(ViewProject::class, ['record' => $dangerProject->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('danger');
});

test('application detail shows package sync status metadata', function () {
    $this->createResourcePermissions('Project');

    $user = $this->actingAsSuperAdmin();
    $syncedAt = now()->subMinutes(12);
    $project = Project::factory()->create([
        'name' => 'Checkout App',
        'created_by' => $user->id,
        'package_key' => 'checkout-app',
        'package_version' => '1.2.3',
        'base_url' => 'https://checkout.example.com',
        'repository' => 'marin-solutions/checkout',
        'last_synced_at' => $syncedAt,
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
        ->assertSee('SDK Version')
        ->assertSee('1.2.3')
        ->assertSee('checkout-app')
        ->assertSee('https://checkout.example.com')
        ->assertSee('marin-solutions/checkout')
        ->assertSeeInOrder(['Synced Checks', '3'])
        ->assertSeeInOrder(['Synced Components', '2']);
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

    $apiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'health',
        'source' => 'package',
        'package_name' => 'health',
        'package_interval' => '5m',
        'last_heartbeat_at' => now()->subMinutes(1),
        'status_summary' => 'API heartbeat succeeded with HTTP status 200.',
        'created_by' => $user->id,
    ]);

    $archivedApiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'legacy-health',
        'source' => 'package',
        'package_name' => 'legacy-health',
        'package_interval' => '15m',
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
        ->assertSeeInOrder(['Synced Checks', '6'])
        ->assertSee('Package-managed Websites')
        ->assertSee('Package-managed APIs');

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$uptimeWebsite, $archivedSslWebsite])
        ->assertSee('Summary')
        ->assertSee('Last Heartbeat')
        ->assertSee('Freshness')
        ->assertSee('Homepage heartbeat succeeded with HTTP status 200.')
        ->assertSee('No heartbeat received within the expected 5m interval.')
        ->assertSee('Fresh')
        ->assertSee('Stale');

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$apiMonitor, $archivedApiMonitor, $disabledApiMonitor])
        ->assertSee('Summary')
        ->assertSee('Last Heartbeat')
        ->assertSee('Freshness')
        ->assertSee('API heartbeat succeeded with HTTP status 200.')
        ->assertSee('Awaiting first package heartbeat.')
        ->assertSee('Fresh')
        ->assertSee('Awaiting heartbeat')
        ->assertSee('Disabled');

    expect(ProjectResource::getRelations())
        ->toContain(PackageManagedWebsitesRelationManager::class)
        ->toContain(PackageManagedApisRelationManager::class);
});

test('super admin can filter applications by current status', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();

    $healthyProject = Project::factory()->create(['name' => 'Healthy App', 'created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $healthyProject->id,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);

    $warningProject = Project::factory()->create(['name' => 'Warning App', 'created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $warningProject->id,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);

    $dangerProject = Project::factory()->create(['name' => 'Danger App', 'created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $dangerProject->id,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);
    ProjectComponent::factory()->create([
        'project_id' => $dangerProject->id,
        'current_status' => 'danger',
        'created_by' => $user->id,
    ]);

    $unknownProject = Project::factory()->create(['name' => 'Unknown App', 'created_by' => $user->id]);

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

    $websiteHealthyProject = Project::factory()->create(['name' => 'Website Healthy App', 'created_by' => $user->id]);
    Website::factory()->create([
        'project_id' => $websiteHealthyProject->id,
        'uptime_check' => true,
        'current_status' => 'healthy',
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
        ->assertCanSeeTableRecords([$dangerProject, $websiteDangerProject, $sslOnlyDangerProject])
        ->assertCanNotSeeTableRecords([$healthyProject, $warningProject, $apiWarningProject, $websiteHealthyProject, $unknownProject, $disabledDangerProject, $uncheckedWebsiteProject, $unknownStatusApiProject]);

    Livewire::test(ListProjects::class)
        ->filterTable('application_status', 'warning')
        ->assertCanSeeTableRecords([$warningProject, $apiWarningProject])
        ->assertCanNotSeeTableRecords([$healthyProject, $dangerProject, $websiteDangerProject, $sslOnlyDangerProject, $websiteHealthyProject, $unknownProject, $disabledDangerProject, $uncheckedWebsiteProject, $unknownStatusApiProject]);

    Livewire::test(ListProjects::class)
        ->filterTable('application_status', 'healthy')
        ->assertCanSeeTableRecords([$healthyProject, $websiteHealthyProject])
        ->assertCanNotSeeTableRecords([$warningProject, $dangerProject, $websiteDangerProject, $sslOnlyDangerProject, $apiWarningProject, $unknownProject, $disabledDangerProject, $uncheckedWebsiteProject, $unknownStatusApiProject]);

    Livewire::test(ListProjects::class)
        ->filterTable('application_status', 'unknown')
        ->assertCanSeeTableRecords([$unknownProject, $disabledDangerProject, $uncheckedWebsiteProject, $unknownStatusApiProject])
        ->assertCanNotSeeTableRecords([$healthyProject, $warningProject, $dangerProject, $websiteDangerProject, $sslOnlyDangerProject, $apiWarningProject, $websiteHealthyProject]);
});

test('application status rolls up enabled websites and api monitors', function () {
    $user = User::factory()->create();

    $healthyProject = Project::factory()->create(['created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $healthyProject->id,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);

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

    $unknownStatusApiProject = Project::factory()->create(['created_by' => $user->id]);
    MonitorApis::factory()->create([
        'project_id' => $unknownStatusApiProject->id,
        'created_by' => $user->id,
        'is_enabled' => true,
        'current_status' => 'unknown',
    ]);

    $mixedSurfaceProject = Project::factory()->create(['created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $mixedSurfaceProject->id,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);
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
        ->and($disabledDangerProject->fresh()->application_status)->toBe('unknown')
        ->and($uncheckedWebsiteProject->fresh()->application_status)->toBe('unknown')
        ->and($unknownStatusApiProject->fresh()->application_status)->toBe('unknown')
        ->and($mixedSurfaceProject->fresh()->application_status)->toBe('danger');
});

test('super admin can filter applications to only failing', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();

    $healthyProject = Project::factory()->create(['name' => 'Healthy App', 'created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $healthyProject->id,
        'current_status' => 'healthy',
        'created_by' => $user->id,
    ]);

    $warningProject = Project::factory()->create(['name' => 'Warning App', 'created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $warningProject->id,
        'current_status' => 'warning',
        'created_by' => $user->id,
    ]);

    $dangerProject = Project::factory()->create(['name' => 'Danger App', 'created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $dangerProject->id,
        'current_status' => 'danger',
        'created_by' => $user->id,
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
        ->assertCanNotSeeTableRecords([$healthyProject, $unknownProject, $archivedFailingProject, $disabledFailingProject]);
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

test('super admin can bulk disable project components', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $components = ProjectComponent::factory()->count(3)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_archived' => false,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->callTableBulkAction('disable', $components);

    foreach ($components as $component) {
        $component->refresh();
        expect($component->is_archived)->toBeTrue()
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
    ]);

    $monitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_enabled' => true,
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'is_archived' => false,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('disable', collect([$project]));

    expect($website->refresh()->uptime_check)->toBeFalse()
        ->and($website->ssl_check)->toBeFalse()
        ->and($website->project_paused_uptime_check)->toBeTrue()
        ->and($website->project_paused_ssl_check)->toBeTrue()
        ->and($monitor->refresh()->is_enabled)->toBeFalse()
        ->and($component->refresh()->is_archived)->toBeTrue();
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
    ]);

    $monitor = MonitorApis::factory()->disabled()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    $component = ProjectComponent::factory()->archived()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($website->refresh()->uptime_check)->toBeTrue()
        ->and($website->ssl_check)->toBeTrue()
        ->and($website->project_paused_uptime_check)->toBeFalse()
        ->and($website->project_paused_ssl_check)->toBeFalse()
        ->and($monitor->refresh()->is_enabled)->toBeTrue()
        ->and($component->refresh()->is_archived)->toBeFalse();
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
    ]);

    $uptimeOnly = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => true,
        'ssl_check' => false,
    ]);

    $sslOnly = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => true,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('disable', collect([$project]));

    expect($bothChecks->refresh()->uptime_check)->toBeFalse()
        ->and($bothChecks->ssl_check)->toBeFalse()
        ->and($bothChecks->project_paused_uptime_check)->toBeTrue()
        ->and($bothChecks->project_paused_ssl_check)->toBeTrue()
        ->and($uptimeOnly->refresh()->uptime_check)->toBeFalse()
        ->and($uptimeOnly->ssl_check)->toBeFalse()
        ->and($uptimeOnly->project_paused_uptime_check)->toBeTrue()
        ->and($uptimeOnly->project_paused_ssl_check)->toBeFalse()
        ->and($sslOnly->refresh()->uptime_check)->toBeFalse()
        ->and($sslOnly->ssl_check)->toBeFalse()
        ->and($sslOnly->project_paused_uptime_check)->toBeFalse()
        ->and($sslOnly->project_paused_ssl_check)->toBeTrue();

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($bothChecks->refresh()->uptime_check)->toBeTrue()
        ->and($bothChecks->ssl_check)->toBeTrue()
        ->and($bothChecks->project_paused_uptime_check)->toBeFalse()
        ->and($bothChecks->project_paused_ssl_check)->toBeFalse()
        ->and($uptimeOnly->refresh()->uptime_check)->toBeTrue()
        ->and($uptimeOnly->ssl_check)->toBeFalse()
        ->and($uptimeOnly->project_paused_uptime_check)->toBeFalse()
        ->and($uptimeOnly->project_paused_ssl_check)->toBeFalse()
        ->and($sslOnly->refresh()->uptime_check)->toBeFalse()
        ->and($sslOnly->ssl_check)->toBeTrue()
        ->and($sslOnly->project_paused_uptime_check)->toBeFalse()
        ->and($sslOnly->project_paused_ssl_check)->toBeFalse();
});

test('bulk resume restores fully paused websites from before project pause flags existed', function () {
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
        'project_paused_uptime_check' => false,
        'project_paused_ssl_check' => false,
    ]);

    $sslOnly = Website::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'uptime_check' => false,
        'ssl_check' => true,
        'project_paused_uptime_check' => false,
        'project_paused_ssl_check' => false,
    ]);

    Livewire::test(ListProjects::class)
        ->callTableBulkAction('enable', collect([$project]));

    expect($legacyPaused->refresh()->uptime_check)->toBeTrue()
        ->and($legacyPaused->ssl_check)->toBeTrue()
        ->and($legacyPaused->project_paused_uptime_check)->toBeFalse()
        ->and($legacyPaused->project_paused_ssl_check)->toBeFalse()
        ->and($sslOnly->refresh()->uptime_check)->toBeFalse()
        ->and($sslOnly->ssl_check)->toBeTrue()
        ->and($sslOnly->project_paused_uptime_check)->toBeFalse()
        ->and($sslOnly->project_paused_ssl_check)->toBeFalse();
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
        ->assertSee('Add a component (cron job, queue worker, or background process) and point its heartbeat at Checkybot to detect stalls and missed runs.')
        ->assertSee('Add component');
});
