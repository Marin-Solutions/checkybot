<?php

use App\Filament\Resources\ApiKeyResource\Pages\CreateApiKey;
use App\Filament\Resources\ApiKeyResource\Pages\EditApiKey;
use App\Filament\Resources\ApiKeyResource\Pages\ListApiKeys;
use App\Models\ApiKey;
use App\Models\User;
use Livewire\Livewire;

test('super admin can render list page', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(ListApiKeys::class)
        ->assertSuccessful();
});

test('super admin can see own api keys in table', function () {
    $user = $this->actingAsSuperAdmin();
    $apiKeys = ApiKey::factory()->count(3)->create(['user_id' => $user->id]);

    Livewire::test(ListApiKeys::class)
        ->assertCanSeeTableRecords($apiKeys);
});

test('super admin cannot see other users api keys', function () {
    $user = $this->actingAsSuperAdmin();
    $ownApiKey = ApiKey::factory()->create(['user_id' => $user->id]);
    $otherApiKey = ApiKey::factory()->create(); // Different user

    Livewire::test(ListApiKeys::class)
        ->assertCanSeeTableRecords([$ownApiKey])
        ->assertCanNotSeeTableRecords([$otherApiKey]);
});

test('super admin can search api keys', function () {
    $user = $this->actingAsSuperAdmin();
    $apiKey1 = ApiKey::factory()->create(['name' => 'Production API Key', 'user_id' => $user->id]);
    $apiKey2 = ApiKey::factory()->create(['name' => 'Development Key', 'user_id' => $user->id]);

    Livewire::test(ListApiKeys::class)
        ->searchTable('Production')
        ->assertCanSeeTableRecords([$apiKey1])
        ->assertCanNotSeeTableRecords([$apiKey2]);
});

test('super admin can render create page', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(CreateApiKey::class)
        ->assertSuccessful();
});

test('super admin can create api key', function () {
    $user = $this->actingAsSuperAdmin();

    Livewire::test(CreateApiKey::class)
        ->fillForm([
            'name' => 'Test API Key',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('api_keys', [
        'name' => 'Test API Key',
        'user_id' => $user->id,
        'is_active' => true,
    ]);
});

test('create api key requires name', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(CreateApiKey::class)
        ->fillForm([
            'name' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

test('super admin can render edit page', function () {
    $user = $this->actingAsSuperAdmin();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

    Livewire::test(EditApiKey::class, ['record' => $apiKey->id])
        ->assertSuccessful();
});

test('super admin can update api key', function () {
    $user = $this->actingAsSuperAdmin();
    $apiKey = ApiKey::factory()->create([
        'name' => 'Old Name',
        'user_id' => $user->id,
    ]);

    Livewire::test(EditApiKey::class, ['record' => $apiKey->id])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('api_keys', [
        'id' => $apiKey->id,
        'name' => 'New Name',
    ]);
});

test('super admin can delete api key', function () {
    $user = $this->actingAsSuperAdmin();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

    Livewire::test(EditApiKey::class, ['record' => $apiKey->id])
        ->callAction('delete');

    $this->assertDatabaseMissing('api_keys', [
        'id' => $apiKey->id,
    ]);
});

test('regular user can access api key resource but sees only own keys', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $ownApiKey = ApiKey::factory()->create(['user_id' => $user->id]);
    $otherApiKey = ApiKey::factory()->create(); // Created by factory's default user

    Livewire::test(ListApiKeys::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$ownApiKey])
        ->assertCanNotSeeTableRecords([$otherApiKey]);
});
