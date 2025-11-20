<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\WebsiteResource\Pages\CreateWebsite;
use App\Filament\Resources\WebsiteResource\Pages\EditWebsite;
use App\Filament\Resources\WebsiteResource\Pages\ListWebsites;
use App\Models\User;
use App\Models\Website;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteResourceTest extends TestCase
{
    public function test_super_admin_can_render_list_page(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(ListWebsites::class)
            ->assertSuccessful();
    }

    public function test_super_admin_can_see_websites_in_table(): void
    {
        $user = $this->actingAsSuperAdmin();
        $websites = Website::factory()->count(3)->create(['created_by' => $user->id]);

        Livewire::test(ListWebsites::class)
            ->assertCanSeeTableRecords($websites);
    }

    public function test_super_admin_can_search_websites(): void
    {
        $user = $this->actingAsSuperAdmin();
        $website1 = Website::factory()->create(['name' => 'Test Site One', 'created_by' => $user->id]);
        $website2 = Website::factory()->create(['name' => 'Another Site', 'created_by' => $user->id]);

        Livewire::test(ListWebsites::class)
            ->searchTable('Test Site')
            ->assertCanSeeTableRecords([$website1])
            ->assertCanNotSeeTableRecords([$website2]);
    }

    public function test_super_admin_can_render_create_page(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateWebsite::class)
            ->assertSuccessful();
    }

    public function test_super_admin_can_create_website(): void
    {
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
    }

    public function test_create_website_requires_url(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateWebsite::class)
            ->fillForm([
                'name' => 'Test Website',
                'url' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['url' => 'required']);
    }

    public function test_super_admin_can_render_edit_page(): void
    {
        $user = $this->actingAsSuperAdmin();
        $website = Website::factory()->create(['created_by' => $user->id]);

        Livewire::test(EditWebsite::class, ['record' => $website->id])
            ->assertSuccessful();
    }

    public function test_super_admin_can_update_website(): void
    {
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
    }

    public function test_super_admin_can_delete_website(): void
    {
        $user = $this->actingAsSuperAdmin();
        $website = Website::factory()->create(['created_by' => $user->id]);

        Livewire::test(EditWebsite::class, ['record' => $website->id])
            ->callAction('delete');

        $this->assertDatabaseMissing('websites', [
            'id' => $website->id,
        ]);
    }

    public function test_regular_user_can_access_website_resource_but_sees_only_own_websites(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create websites for this user and another user
        $ownWebsite = Website::factory()->create(['created_by' => $user->id]);
        $otherWebsite = Website::factory()->create(); // Created by factory's default user

        Livewire::test(ListWebsites::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ownWebsite])
            ->assertCanNotSeeTableRecords([$otherWebsite]);
    }
}
