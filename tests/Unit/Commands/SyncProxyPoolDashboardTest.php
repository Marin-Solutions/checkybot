<?php

use App\Models\Project;
use App\Models\ProxyPoolIntegration;
use App\Services\ProxyPoolDashboardService;
use Illuminate\Support\Facades\Http;

it('syncs configured proxy pools from the artisan command', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $integration = ProxyPoolIntegration::factory()->create([
        'name' => 'Production',
        'base_url' => 'https://proxy.test',
        'token' => 'secret-token',
        'project_id' => $project->id,
        'created_by' => $user->id,
        'check_interval' => '10m',
    ]);

    Http::fake([
        'proxy.test/api/v1/rest/dashboard*' => Http::response(['data' => [
            'accounts_expiring_soon' => 0,
            'healthy_proxies' => 12,
            'unhealthy_proxies' => 0,
            'slow_proxies' => 0,
        ]]),
    ]);

    $this->artisan('proxy-pool:sync-dashboard')
        ->assertSuccessful();

    assertDatabaseHas('project_components', [
        'project_id' => $project->id,
        'name' => 'Proxy Pool: Production',
        'source' => ProxyPoolDashboardService::COMPONENT_SOURCE,
        'current_status' => 'healthy',
    ]);

    expect($integration->refresh()->last_sync_status)->toBe('healthy');
});

it('skips inactive proxy pool integrations from the artisan command', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    ProxyPoolIntegration::factory()->inactive()->create([
        'name' => 'Inactive',
        'base_url' => 'https://proxy.test',
        'token' => 'secret-token',
        'project_id' => $project->id,
        'created_by' => $user->id,
    ]);

    Http::fake(function (): never {
        throw new RuntimeException('The inactive integration should not be requested.');
    });

    $this->artisan('proxy-pool:sync-dashboard')
        ->assertSuccessful();

    expect(\App\Models\ProjectComponent::query()->count())->toBe(0);
});

it('continues syncing remaining proxy pools when one integration has an unexpected failure', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $badIntegration = ProxyPoolIntegration::factory()->create([
        'name' => 'Bad Interval',
        'project_id' => $project->id,
        'created_by' => $user->id,
        'check_interval' => 'invalid',
    ]);
    $goodIntegration = ProxyPoolIntegration::factory()->create([
        'name' => 'Production',
        'base_url' => 'https://proxy.test',
        'token' => 'secret-token',
        'project_id' => $project->id,
        'created_by' => $user->id,
        'check_interval' => '10m',
    ]);

    Http::fake([
        'proxy.test/api/v1/rest/dashboard*' => Http::response(['data' => [
            'accounts_expiring_soon' => 0,
            'healthy_proxies' => 12,
            'unhealthy_proxies' => 0,
            'slow_proxies' => 0,
        ]]),
    ]);

    $this->artisan('proxy-pool:sync-dashboard')
        ->assertSuccessful();

    expect($badIntegration->refresh()->last_sync_status)->toBe('danger')
        ->and($badIntegration->last_sync_error)->toContain('invalid interval')
        ->and($goodIntegration->refresh()->last_sync_status)->toBe('healthy');

    assertDatabaseHas('project_components', [
        'project_id' => $project->id,
        'name' => 'Proxy Pool: Production',
        'source' => ProxyPoolDashboardService::COMPONENT_SOURCE,
        'current_status' => 'healthy',
    ]);
});
