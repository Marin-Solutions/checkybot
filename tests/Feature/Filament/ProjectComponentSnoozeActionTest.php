<?php

use App\Filament\Resources\ProjectComponents\Pages\ListProjectComponents;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use Livewire\Livewire;

test('super admin can snooze a project component for 1 hour via the row action', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->callTableAction('snooze', $component, data: [
            'duration' => '1h',
        ])
        ->assertHasNoTableActionErrors();

    $component->refresh();

    expect($component->silenced_until)->not->toBeNull()
        ->and($component->silenced_until->isFuture())->toBeTrue()
        ->and(now()->diffInMinutes($component->silenced_until))->toBeGreaterThanOrEqual(55)
        ->and(now()->diffInMinutes($component->silenced_until))->toBeLessThanOrEqual(65);
});

test('bulk snooze pauses notifications for selected project components', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $components = ProjectComponent::factory()->count(2)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'silenced_until' => null,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->callTableBulkAction('snooze', $components, data: ['duration' => '4h']);

    foreach ($components as $component) {
        $component->refresh();
        expect($component->silenced_until)->not->toBeNull()
            ->and($component->silenced_until->isFuture())->toBeTrue();
    }
});

test('bulk unsnooze clears silenced_until on selected project components', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);
    $components = ProjectComponent::factory()->count(2)->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'silenced_until' => now()->addHour(),
    ]);

    Livewire::test(ListProjectComponents::class)
        ->callTableBulkAction('unsnooze', $components);

    foreach ($components as $component) {
        expect($component->refresh()->silenced_until)->toBeNull();
    }
});

test('user without Update:ProjectComponent permission cannot see component snooze actions', function () {
    $this->createResourcePermissions('ProjectComponent');

    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo('ViewAny:ProjectComponent');
    $this->actingAs($user);

    $project = Project::factory()->create(['created_by' => $user->id]);
    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    Livewire::test(ListProjectComponents::class)
        ->assertTableActionHidden('snooze')
        ->assertTableBulkActionHidden('snooze')
        ->assertTableBulkActionHidden('unsnooze');
});
