<?php

use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        ->assertSee('Create API Key')
        ->assertSee('Manage API Keys');

    expect($project->guidedSetupSnippet())
        ->toContain('CHECKYBOT_API_KEY=replace-with-your-api-key')
        ->toContain("CHECKYBOT_APP_ID={$project->getKey()}")
        ->toContain('CHECKYBOT_APPLICATION_NAME="Checkout App"')
        ->toContain("Checkybot::component('queue')")
        ->toContain("->metric('pending_jobs', fn (): int => \\Illuminate\\Support\\Facades\\Queue::size('default'))")
        ->toContain("Checkybot::component('scheduled-jobs')")
        ->toContain('Schedule::command(\'checkybot:sync\')->everyMinute();');
});

test('application view can create an api key inline and update the guided setup snippet', function () {
    $this->createResourcePermissions('Project');

    $user = $this->actingAsSuperAdmin();

    $project = Project::factory()->create([
        'name' => 'Checkout App',
        'environment' => 'production',
        'created_by' => $user->id,
    ]);

    $component = Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertInfolistActionExists('guided_setup', 'createApiKey')
        ->callInfolistAction('guided_setup', 'createApiKey', data: [
            'name' => 'Checkout App setup key',
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $generatedKey = $component->get('guidedSetupApiKey');
    $apiKey = ApiKey::query()->where('name', 'Checkout App setup key')->firstOrFail();
    $storedApiKey = DB::table('api_keys')->where('id', $apiKey->id)->first();

    expect($generatedKey)->toStartWith('ck_')
        ->and($component->get('guidedSetupApiKeyName'))->toBe('Checkout App setup key')
        ->and($storedApiKey->key_hash)->toBe(ApiKey::hashKey($generatedKey))
        ->and($storedApiKey->key)->not->toBe($generatedKey);

    $component
        ->assertSee('API key created for this setup flow')
        ->assertSee('Copy updated snippet')
        ->assertSee($generatedKey)
        ->assertSee("CHECKYBOT_API_KEY={$generatedKey}")
        ->assertSee("CHECKYBOT_APP_ID={$project->getKey()}")
        ->call('dismissGuidedSetupApiKey')
        ->assertDontSee($generatedKey);

    expect($component->get('guidedSetupApiKey'))->toBeNull()
        ->and($component->get('guidedSetupApiKeyName'))->toBeNull();
});

test('non-admin cannot create an api key inline from the application view', function () {
    $this->createResourcePermissions('Project');

    $user = User::factory()->create();
    $user->givePermissionTo('View:Project');
    $this->actingAs($user);

    $project = Project::factory()->create([
        'name' => 'Checkout App',
        'environment' => 'production',
        'created_by' => $user->id,
    ]);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertSuccessful()
        ->assertInfolistActionDoesNotExist('guided_setup', 'createApiKey')
        ->assertInfolistActionDoesNotExist('guided_setup', 'manageApiKeys')
        ->call('issueGuidedSetupApiKey', ['name' => 'Blocked key'])
        ->assertForbidden();
});
