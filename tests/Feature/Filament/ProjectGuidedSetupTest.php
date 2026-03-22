<?php

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Project;
use Livewire\Livewire;

test('operator can create an application with only name and environment', function () {
    $this->createResourcePermissions('Project');

    $user = $this->actingAsSuperAdmin();

    Livewire::test(CreateProject::class)
        ->fillForm([
            'name' => 'Checkout App',
            'environment' => 'production',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $project = Project::query()->sole();

    expect($project->name)->toBe('Checkout App')
        ->and($project->environment)->toBe('production')
        ->and($project->created_by)->toBe($user->id)
        ->and($project->technology)->toBeNull()
        ->and($project->group)->toBeNull()
        ->and($project->token)->not->toBeEmpty();
});

test('application view shows the guided Laravel setup snippet with pairing data', function () {
    $this->createResourcePermissions('Project');

    $user = $this->actingAsSuperAdmin();

    config()->set('app.url', 'https://checkybot.example.com');

    $project = Project::factory()->create([
        'name' => 'Checkout App',
        'environment' => 'production',
        'technology' => null,
        'group' => null,
        'created_by' => $user->id,
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Guided Laravel Setup')
        ->assertSchemaComponentStateSet('guided_setup_snippet', $project->guidedSetupSnippet(), 'infolist');

    expect($project->guidedSetupSnippet())
        ->toContain('Schedule::command(\'checkybot:sync\')->everyMinute();');
});
