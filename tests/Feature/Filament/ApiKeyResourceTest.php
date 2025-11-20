<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApiKeyResource\Pages\CreateApiKey;
use App\Filament\Resources\ApiKeyResource\Pages\EditApiKey;
use App\Filament\Resources\ApiKeyResource\Pages\ListApiKeys;
use App\Models\ApiKey;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

class ApiKeyResourceTest extends TestCase
{
    public function test_super_admin_can_render_list_page(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(ListApiKeys::class)
            ->assertSuccessful();
    }

    public function test_super_admin_can_see_own_api_keys_in_table(): void
    {
        $user = $this->actingAsSuperAdmin();
        $apiKeys = ApiKey::factory()->count(3)->create(['user_id' => $user->id]);

        Livewire::test(ListApiKeys::class)
            ->assertCanSeeTableRecords($apiKeys);
    }

    public function test_super_admin_cannot_see_other_users_api_keys(): void
    {
        $user = $this->actingAsSuperAdmin();
        $ownApiKey = ApiKey::factory()->create(['user_id' => $user->id]);
        $otherApiKey = ApiKey::factory()->create(); // Different user

        Livewire::test(ListApiKeys::class)
            ->assertCanSeeTableRecords([$ownApiKey])
            ->assertCanNotSeeTableRecords([$otherApiKey]);
    }

    public function test_super_admin_can_search_api_keys(): void
    {
        $user = $this->actingAsSuperAdmin();
        $apiKey1 = ApiKey::factory()->create(['name' => 'Production API Key', 'user_id' => $user->id]);
        $apiKey2 = ApiKey::factory()->create(['name' => 'Development Key', 'user_id' => $user->id]);

        Livewire::test(ListApiKeys::class)
            ->searchTable('Production')
            ->assertCanSeeTableRecords([$apiKey1])
            ->assertCanNotSeeTableRecords([$apiKey2]);
    }

    public function test_super_admin_can_render_create_page(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateApiKey::class)
            ->assertSuccessful();
    }

    public function test_super_admin_can_create_api_key(): void
    {
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
    }

    public function test_create_api_key_requires_name(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateApiKey::class)
            ->fillForm([
                'name' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    }

    public function test_super_admin_can_render_edit_page(): void
    {
        $user = $this->actingAsSuperAdmin();
        $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

        Livewire::test(EditApiKey::class, ['record' => $apiKey->id])
            ->assertSuccessful();
    }

    public function test_super_admin_can_update_api_key(): void
    {
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
    }

    public function test_super_admin_can_delete_api_key(): void
    {
        $user = $this->actingAsSuperAdmin();
        $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);

        Livewire::test(EditApiKey::class, ['record' => $apiKey->id])
            ->callAction('delete');

        $this->assertDatabaseMissing('api_keys', [
            'id' => $apiKey->id,
        ]);
    }

    public function test_regular_user_can_access_api_key_resource_but_sees_only_own_keys(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $ownApiKey = ApiKey::factory()->create(['user_id' => $user->id]);
        $otherApiKey = ApiKey::factory()->create(); // Created by factory's default user

        Livewire::test(ListApiKeys::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ownApiKey])
            ->assertCanNotSeeTableRecords([$otherApiKey]);
    }
}
