<?php

use App\Filament\Resources\ProjectComponents\Pages\ListProjectComponents;
use App\Filament\Resources\ProjectComponents\Pages\ViewProjectComponent;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProjectComponentHeartbeat;
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
