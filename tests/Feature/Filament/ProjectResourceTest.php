<?php

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

test('project component detail shows immediate stale threshold for zero-minute intervals', function () {
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
        ->assertSee('Expired');
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

test('application record shows package-managed external checks including archived ones', function () {
    $this->createResourcePermissions('Project');
    $this->createResourcePermissions('MonitorApis');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create([
        'name' => 'Payments App',
        'created_by' => $user->id,
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
        ->and($monitor->refresh()->is_enabled)->toBeTrue()
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
