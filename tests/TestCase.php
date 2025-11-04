<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable Telescope, Horizon, and Pulse for tests
        config(['telescope.enabled' => false]);
        config(['horizon.enabled' => false]);
        config(['pulse.enabled' => false]);

        // Run migrations
        $this->artisan('migrate:fresh');

        // Seed permissions and roles if needed
        $this->artisan('shield:install', ['--fresh' => true, '--minimal' => true]);
    }

    /**
     * Create an authenticated user for testing
     */
    protected function actingAsUser(?string $role = null): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();

        if ($role) {
            $user->assignRole($role);
        }

        $this->actingAs($user);

        return $user;
    }

    /**
     * Create a super admin user for testing
     */
    protected function actingAsSuperAdmin(): \App\Models\User
    {
        $user = \App\Models\User::factory()->create();
        $user->assignRole('Super Admin');

        $this->actingAs($user);

        return $user;
    }

    /**
     * Create an admin user for testing
     */
    protected function actingAsAdmin(): \App\Models\User
    {
        return $this->actingAsUser('Admin');
    }

    /**
     * Assert that a database query count is within expectations
     */
    protected function assertQueryCount(int $expected, callable $callback): void
    {
        $queries = 0;

        \DB::listen(function () use (&$queries) {
            $queries++;
        });

        $callback();

        $this->assertEquals($expected, $queries, "Expected {$expected} queries, but {$queries} were executed");
    }
}
