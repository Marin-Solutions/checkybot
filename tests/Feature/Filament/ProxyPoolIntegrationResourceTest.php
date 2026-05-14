<?php

use App\Filament\Resources\ProxyPoolIntegrationResource\Pages\EditProxyPoolIntegration;
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

test('editing non-credential fields keeps token and sync state', function () {
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
        'last_sync_status' => 'healthy',
        'last_sync_error' => null,
        'last_synced_at' => now()->subMinute(),
    ]);
    $syncedAt = $integration->last_synced_at;

    Livewire::test(EditProxyPoolIntegration::class, ['record' => $integration->getRouteKey()])
        ->fillForm([
            'name' => 'Production Proxy Pool',
            'project_id' => $project->id,
            'base_url' => 'https://proxy.test',
            'check_interval' => '5m',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($integration->refresh())
        ->name->toBe('Production Proxy Pool')
        ->token->toBe('secret-token')
        ->last_sync_status->toBe('healthy')
        ->last_synced_at->equalTo($syncedAt);
});

test('editing credentials resets sync state', function () {
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
        'last_sync_status' => 'healthy',
        'last_sync_error' => null,
        'last_synced_at' => now()->subMinute(),
    ]);

    Livewire::test(EditProxyPoolIntegration::class, ['record' => $integration->getRouteKey()])
        ->fillForm([
            'name' => 'Production Proxies',
            'project_id' => $project->id,
            'base_url' => 'https://proxy-v2.test',
            'token' => 'new-secret-token',
            'check_interval' => '5m',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($integration->refresh())
        ->base_url->toBe('https://proxy-v2.test')
        ->token->toBe('new-secret-token')
        ->last_sync_status->toBeNull()
        ->last_sync_error->toBeNull()
        ->last_synced_at->toBeNull();
});
