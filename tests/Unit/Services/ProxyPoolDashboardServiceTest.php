<?php

use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProxyPoolIntegration;
use App\Services\ProxyPoolDashboardService;
use Illuminate\Support\Facades\Http;

it('records proxy pool dashboard metrics on the project component', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    Http::fake([
        'proxy.test/api/v1/rest/dashboard*' => Http::response([
            'data' => [
                'accounts_expiring_soon' => 2,
                'healthy_proxies' => 12,
                'unhealthy_proxies' => 1,
                'slow_proxies' => 3,
                'thresholds' => [
                    'expiring_soon_months' => 3,
                    'slow_connect_ms' => 10000,
                ],
            ],
        ]),
    ]);

    $integration = ProxyPoolIntegration::factory()->create([
        'name' => 'Production',
        'base_url' => 'https://proxy.test',
        'token' => 'secret-token',
        'project_id' => $project->id,
        'created_by' => $user->id,
        'check_interval' => '5m',
    ]);

    $component = app(ProxyPoolDashboardService::class)->syncIntegration($integration);

    expect($component->refresh())
        ->name->toBe('Proxy Pool: Production')
        ->source->toBe(ProxyPoolDashboardService::COMPONENT_SOURCE)
        ->current_status->toBe('danger')
        ->summary->toBe('2 accounts expiring soon, 1 unhealthy proxies, 3 slow proxies, 12 healthy proxies.')
        ->and($component->metrics['attention_total'])->toBe(6)
        ->and($integration->refresh()->last_sync_status)->toBe('danger')
        ->and($integration->last_sync_error)->toBeNull()
        ->and($integration->last_synced_at)->not->toBeNull();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://proxy.test/api/v1/rest/dashboard?token=secret-token');
});

it('records a danger heartbeat when the proxy pool api cannot be consumed', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    Http::fake([
        'proxy.test/api/v1/rest/dashboard*' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    $integration = ProxyPoolIntegration::factory()->create([
        'name' => 'Production',
        'base_url' => 'https://proxy.test',
        'token' => 'wrong-token',
        'project_id' => $project->id,
        'created_by' => $user->id,
        'check_interval' => '5m',
    ]);

    $component = app(ProxyPoolDashboardService::class)->syncIntegration($integration);

    expect($component->refresh())
        ->current_status->toBe('danger')
        ->and($component->summary)->toContain('Proxy pool API check failed')
        ->and($component->metrics['attention_total'])->toBe(1)
        ->and($integration->refresh()->last_sync_status)->toBe('danger')
        ->and($integration->last_sync_error)->not->toBeNull();
});

it('updates the same proxy pool component on later syncs', function () {
    $user = $this->actingAsSuperAdmin();
    $project = Project::factory()->create(['created_by' => $user->id]);

    Http::fakeSequence()
        ->push(['data' => [
            'accounts_expiring_soon' => 0,
            'healthy_proxies' => 10,
            'unhealthy_proxies' => 0,
            'slow_proxies' => 0,
        ]])
        ->push(['data' => [
            'accounts_expiring_soon' => 1,
            'healthy_proxies' => 9,
            'unhealthy_proxies' => 0,
            'slow_proxies' => 2,
        ]]);

    $service = app(ProxyPoolDashboardService::class);

    $integration = ProxyPoolIntegration::factory()->create([
        'name' => 'Production',
        'base_url' => 'https://proxy.test',
        'token' => 'secret-token',
        'project_id' => $project->id,
        'created_by' => $user->id,
        'check_interval' => '5m',
    ]);

    $first = $service->syncIntegration($integration);
    $second = $service->syncIntegration($integration);

    expect($second->id)->toBe($first->id)
        ->and(ProjectComponent::query()->where('source', ProxyPoolDashboardService::COMPONENT_SOURCE)->count())->toBe(1)
        ->and($second->refresh()->current_status)->toBe('warning')
        ->and($second->metrics['attention_total'])->toBe(3);
});
