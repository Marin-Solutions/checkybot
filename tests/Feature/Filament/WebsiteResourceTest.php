<?php

use App\Filament\Resources\WebsiteResource\Pages\CreateWebsite;
use App\Filament\Resources\WebsiteResource\Pages\EditWebsite;
use App\Filament\Resources\WebsiteResource\Pages\ListWebsites;
use App\Models\User;
use App\Models\Website;
use Livewire\Livewire;

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

    // Use an existing valid website URL instead of mocking
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

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('websites', [
        'id' => $website->id,
        'name' => 'New Name',
    ]);
});

test('super admin can delete website', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->callAction('delete');

    $this->assertDatabaseMissing('websites', [
        'id' => $website->id,
    ]);
});

test('regular user can access website resource but sees only own websites', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Create websites for this user and another user
    $ownWebsite = Website::factory()->create(['created_by' => $user->id]);
    $otherWebsite = Website::factory()->create(); // Created by factory's default user

    Livewire::test(ListWebsites::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$ownWebsite])
        ->assertCanNotSeeTableRecords([$otherWebsite]);
});
