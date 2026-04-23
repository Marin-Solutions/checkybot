<?php

use App\Filament\Resources\WebsiteResource\Pages\CreateWebsite;
use App\Filament\Resources\WebsiteResource\Pages\EditWebsite;
use App\Filament\Resources\WebsiteResource\Pages\ListWebsites;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Dns\Dns;

test('super admin can render list page', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(ListWebsites::class)
        ->assertSuccessful();
});

test('super admin can see websites in table', function () {
    $user = $this->actingAsSuperAdmin();
    $websites = Website::factory()->count(3)->create(['created_by' => $user->id]);

    Livewire::test(ListWebsites::class)
        ->assertCanSeeTableRecords($websites);
});

test('super admin can search websites', function () {
    $user = $this->actingAsSuperAdmin();
    $website1 = Website::factory()->create(['name' => 'Test Site One', 'created_by' => $user->id]);
    $website2 = Website::factory()->create(['name' => 'Another Site', 'created_by' => $user->id]);

    Livewire::test(ListWebsites::class)
        ->searchTable('Test Site')
        ->assertCanSeeTableRecords([$website1])
        ->assertCanNotSeeTableRecords([$website2]);
});

test('super admin can render create page', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(CreateWebsite::class)
        ->assertSuccessful();
});

test('super admin can create website', function () {
    $user = $this->actingAsSuperAdmin();

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('example.com', 'A')
        ->andReturn([['ip' => '192.0.2.1']]);

    Http::fake([
        'https://example.com' => Http::response('OK', 200),
    ]);

    Livewire::test(CreateWebsite::class)
        ->fillForm([
            'name' => 'Test Website',
            'url' => 'https://example.com',
            'description' => 'Test description',
            'uptime_check' => true,
            'uptime_interval' => 60,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('websites', [
        'name' => 'Test Website',
        'url' => 'https://example.com',
        'created_by' => $user->id,
    ]);
});

test('create website requires url', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(CreateWebsite::class)
        ->fillForm([
            'name' => 'Test Website',
            'url' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['url' => 'required']);
});

test('super admin can render edit page', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->assertSuccessful();
});

test('super admin can update website', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'name' => 'Old Name',
        'created_by' => $user->id,
        'url' => 'https://example.com',
    ]);

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('example.com', 'A')
        ->andReturn([['ip' => '192.0.2.1']]);

    Http::fake([
        'https://example.com' => Http::response('OK', 200),
    ]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('websites', [
        'id' => $website->id,
        'name' => 'New Name',
    ]);
});

test('super admin can create website with warning state when target checks fail', function () {
    $user = $this->actingAsSuperAdmin();

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('broken.example', 'A')
        ->andReturn([]);

    Http::fake([
        'https://broken.example' => Http::response('Not Found', 404),
    ]);

    Livewire::test(CreateWebsite::class)
        ->fillForm([
            'name' => 'Broken Website',
            'url' => 'https://broken.example',
            'description' => 'Broken target',
            'uptime_check' => true,
            'uptime_interval' => 60,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('websites', [
        'name' => 'Broken Website',
        'url' => 'https://broken.example',
        'created_by' => $user->id,
        'current_status' => 'warning',
    ]);

    expect(Website::where('url', 'https://broken.example')->first()?->status_summary)
        ->toContain('The domain did not resolve during setup.')
        ->toContain('HTTP 404');
});

test('super admin can update website and keep save flow when target checks fail', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'name' => 'Healthy Website',
        'created_by' => $user->id,
        'url' => 'https://example.com',
        'current_status' => 'healthy',
        'status_summary' => 'Heartbeat received successfully.',
    ]);

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('broken.example', 'A')
        ->andReturn([]);

    Http::fake([
        'https://broken.example' => Http::response('Service Unavailable', 503),
    ]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->fillForm(['url' => 'https://broken.example'])
        ->call('save')
        ->assertHasNoFormErrors();

    $website->refresh();

    expect($website->url)->toBe('https://broken.example')
        ->and($website->current_status)->toBe('warning')
        ->and($website->status_summary)->toContain('The domain did not resolve during setup.')
        ->and($website->status_summary)->toContain('HTTP 503');
});

test('super admin can delete website', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->callAction('delete');

    $this->assertSoftDeleted('websites', [
        'id' => $website->id,
    ]);
});

test('super admin can filter to archived websites', function () {
    $user = $this->actingAsSuperAdmin();

    $activeWebsite = Website::factory()->create(['created_by' => $user->id]);
    $archivedWebsite = Website::factory()->create([
        'created_by' => $user->id,
        'source' => 'package',
        'package_name' => 'archived-website',
    ]);

    $archivedWebsite->delete();

    Livewire::test(ListWebsites::class)
        ->filterTable('trashed', 'only')
        ->assertCanSeeTableRecords([$archivedWebsite]);
});

test('regular user cannot access website resource', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(ListWebsites::class)
        ->assertForbidden();
});
