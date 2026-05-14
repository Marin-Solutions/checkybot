<?php

use App\Filament\Resources\ProxyPoolIntegrationResource\Pages\ListProxyPoolIntegrations;
use App\Models\Project;
use App\Models\ProxyPoolIntegration;
use App\Policies\ProxyPoolIntegrationPolicy;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

test('proxy pool integration list only shows integrations created by the current user', function () {
    $this->createResourcePermissions('ProxyPoolIntegration');

    $user = $this->actingAsAdmin();
    $user->givePermissionTo(['ViewAny:ProxyPoolIntegration', 'View:ProxyPoolIntegration']);
    $project = Project::factory()->create(['created_by' => $user->id]);

    $ownIntegration = ProxyPoolIntegration::factory()->create([
        'created_by' => $user->id,
        'project_id' => $project->id,
        'name' => 'Production Proxies',
    ]);
    $otherIntegration = ProxyPoolIntegration::factory()->create([
        'name' => 'Other Team Proxies',
    ]);

    Livewire::test(ListProxyPoolIntegrations::class)
        ->assertCanSeeTableRecords([$ownIntegration])
        ->assertCanNotSeeTableRecords([$otherIntegration])
        ->assertSee('Production Proxies')
        ->assertDontSee('Other Team Proxies');
});

test('proxy pool integration can sync from the dashboard action', function () {
    $this->createResourcePermissions('ProxyPoolIntegration');

    $user = $this->actingAsAdmin();
    $user->givePermissionTo([
        'ViewAny:ProxyPoolIntegration',
        'View:ProxyPoolIntegration',
        'Update:ProxyPoolIntegration',
    ]);
    $project = Project::factory()->create(['created_by' => $user->id]);
    $integration = ProxyPoolIntegration::factory()->create([
        'created_by' => $user->id,
        'project_id' => $project->id,
        'name' => 'Production Proxies',
        'base_url' => 'https://proxy.test',
        'token' => 'secret-token',
    ]);

    Http::fake([
        'proxy.test/api/v1/rest/dashboard*' => Http::response(['data' => [
            'accounts_expiring_soon' => 2,
            'healthy_proxies' => 12,
            'unhealthy_proxies' => 0,
            'slow_proxies' => 3,
        ]]),
    ]);

    Livewire::test(ListProxyPoolIntegrations::class)
        ->callTableAction('sync', $integration)
        ->assertHasNoTableActionErrors();

    expect($integration->refresh()->last_sync_status)->toBe('warning');

    assertDatabaseHas('project_components', [
        'project_id' => $project->id,
        'name' => 'Proxy Pool: Production Proxies',
        'current_status' => 'warning',
    ]);
});

test('proxy pool integration policy denies another users integration even with permissions', function () {
    $this->createResourcePermissions('ProxyPoolIntegration');

    $user = $this->actingAsAdmin();
    $user->givePermissionTo([
        'View:ProxyPoolIntegration',
        'Update:ProxyPoolIntegration',
        'Delete:ProxyPoolIntegration',
        'Restore:ProxyPoolIntegration',
        'ForceDelete:ProxyPoolIntegration',
        'Replicate:ProxyPoolIntegration',
    ]);

    $project = Project::factory()->create(['created_by' => $user->id]);
    $ownIntegration = ProxyPoolIntegration::factory()->create([
        'created_by' => $user->id,
        'project_id' => $project->id,
    ]);
    $otherIntegration = ProxyPoolIntegration::factory()->create();
    $policy = new ProxyPoolIntegrationPolicy;

    expect($policy->view($user, $ownIntegration))->toBeTrue()
        ->and($policy->update($user, $ownIntegration))->toBeTrue()
        ->and($policy->delete($user, $ownIntegration))->toBeTrue()
        ->and($policy->restore($user, $ownIntegration))->toBeTrue()
        ->and($policy->forceDelete($user, $ownIntegration))->toBeTrue()
        ->and($policy->replicate($user, $ownIntegration))->toBeTrue()
        ->and($policy->view($user, $otherIntegration))->toBeFalse()
        ->and($policy->update($user, $otherIntegration))->toBeFalse()
        ->and($policy->delete($user, $otherIntegration))->toBeFalse()
        ->and($policy->restore($user, $otherIntegration))->toBeFalse()
        ->and($policy->forceDelete($user, $otherIntegration))->toBeFalse()
        ->and($policy->replicate($user, $otherIntegration))->toBeFalse();
});
