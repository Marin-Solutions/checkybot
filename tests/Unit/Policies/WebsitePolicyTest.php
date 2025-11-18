<?php

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Models\Website;
use App\Policies\WebsitePolicy;
use Tests\TestCase;

class WebsitePolicyTest extends TestCase
{
    protected WebsitePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new WebsitePolicy;
    }

    public function test_super_admin_can_view_any_websites(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $this->assertTrue($this->policy->viewAny($superAdmin));
    }

    public function test_super_admin_can_view_website(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();
        $website = Website::factory()->create();

        $this->assertTrue($this->policy->view($superAdmin, $website));
    }

    public function test_super_admin_can_create_websites(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $this->assertTrue($this->policy->create($superAdmin));
    }

    public function test_super_admin_can_update_websites(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();
        $website = Website::factory()->create();

        $this->assertTrue($this->policy->update($superAdmin, $website));
    }

    public function test_super_admin_can_delete_websites(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();
        $website = Website::factory()->create();

        $this->assertTrue($this->policy->delete($superAdmin, $website));
    }

    public function test_user_with_permission_can_view_any_websites(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('ViewAny:Website');

        $this->assertTrue($this->policy->viewAny($user));
    }

    public function test_user_without_permission_cannot_view_any_websites(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->viewAny($user));
    }

    public function test_user_with_permission_can_create_websites(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('Create:Website');

        $this->assertTrue($this->policy->create($user));
    }

    public function test_user_without_permission_cannot_create_websites(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->create($user));
    }
}
