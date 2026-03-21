<?php

use App\Filament\Resources\ProjectComponents\Pages\ListProjectComponents;
use App\Filament\Resources\ProjectComponents\Pages\ViewProjectComponent;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\RelationManagers\PackageManagedApisRelationManager;
use App\Filament\Resources\Projects\RelationManagers\PackageManagedWebsitesRelationManager;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProjectComponentHeartbeat;
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
    ]);

    ProjectComponentHeartbeat::factory()->create([
        'project_component_id' => $archivedComponent->id,
        'component_name' => 'legacy-proxy',
        'status' => 'danger',
        'event' => 'stale',
        'summary' => 'Heartbeat expired',
    ]);

    Livewire::test(ViewProjectComponent::class, ['record' => $archivedComponent->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Heartbeat expired')
        ->assertSee('stale');
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
        'uptime_check' => true,
        'ssl_check' => false,
        'created_by' => $user->id,
    ]);

    $archivedSslWebsite = Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'certificate',
        'source' => 'package',
        'package_name' => 'certificate',
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
        'created_by' => $user->id,
    ]);

    $archivedApiMonitor = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'legacy-health',
        'source' => 'package',
        'package_name' => 'legacy-health',
        'created_by' => $user->id,
    ]);
    $archivedApiMonitor->delete();

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Package-managed Websites')
        ->assertSee('Package-managed APIs');

    Livewire::test(PackageManagedWebsitesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$uptimeWebsite, $archivedSslWebsite]);

    Livewire::test(PackageManagedApisRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$apiMonitor, $archivedApiMonitor]);

    expect(ProjectResource::getRelations())
        ->toContain(PackageManagedWebsitesRelationManager::class)
        ->toContain(PackageManagedApisRelationManager::class);
});
