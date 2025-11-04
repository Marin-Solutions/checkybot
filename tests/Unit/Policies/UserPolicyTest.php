<?php

namespace Tests\Unit\Policies;

use App\Models\User;
use App\Policies\UserPolicy;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    protected UserPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new UserPolicy;
    }

    public function test_super_admin_can_view_any_users(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $this->assertTrue($this->policy->viewAny($superAdmin));
    }

    public function test_regular_user_cannot_view_any_users_without_permission(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->viewAny($user));
    }

    public function test_user_with_permission_can_view_any_users(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('ViewAny:User');

        $this->assertTrue($this->policy->viewAny($user));
    }

    public function test_super_admin_can_create_users(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $this->assertTrue($this->policy->create($superAdmin));
    }

    public function test_super_admin_can_update_users(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $this->assertTrue($this->policy->update($superAdmin));
    }

    public function test_super_admin_can_delete_users(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $this->assertTrue($this->policy->delete($superAdmin));
    }

    public function test_user_without_permission_cannot_create_users(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->policy->create($user));
    }
}
