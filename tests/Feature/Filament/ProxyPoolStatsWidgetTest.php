<?php

use App\Filament\Widgets\ProxyPoolStatsWidget;
use App\Models\ProjectComponent;
use App\Services\ProxyPoolDashboardService;
use Livewire\Livewire;

it('shows proxy pool attention totals on the dashboard widget', function () {
    $user = $this->actingAsSuperAdmin();

    ProjectComponent::factory()->create([
        'created_by' => $user->id,
        'source' => ProxyPoolDashboardService::COMPONENT_SOURCE,
        'current_status' => 'warning',
        'metrics' => [
            'attention_total' => 5,
            'accounts_expiring_soon' => 2,
            'unhealthy_proxies' => 0,
            'slow_proxies' => 3,
            'healthy_proxies' => 12,
        ],
    ]);

    Livewire::test(ProxyPoolStatsWidget::class)
        ->assertSuccessful()
        ->assertSee('Proxy Pools')
        ->assertSee('Proxy Items To Review')
        ->assertSee('5')
        ->assertSee('2 renewals, 0 unhealthy, 3 slow');
});
