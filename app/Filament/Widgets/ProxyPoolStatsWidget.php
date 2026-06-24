<?php

namespace App\Filament\Widgets;

use App\Models\ProjectComponent;
use App\Services\ProxyPoolDashboardService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProxyPoolStatsWidget extends BaseWidget
{
    /**
     * @var array<string>
     */
    public array $discoveredSchemaNames = [];

    public bool $areSchemaStateUpdateHooksDisabledForTesting = false;

    protected static ?int $sort = 3;

    protected ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return false;
    }

    protected function getStats(): array
    {
        $components = ProjectComponent::query()
            ->where('created_by', auth()->id())
            ->where('source', ProxyPoolDashboardService::COMPONENT_SOURCE)
            ->where('is_archived', false)
            ->get(['current_status', 'metrics']);

        if ($components->isEmpty()) {
            return [
                Stat::make('Proxy Pools', 0)
                    ->description('No proxy pool integrations configured')
                    ->descriptionIcon('heroicon-m-globe-alt')
                    ->color('gray'),
            ];
        }

        $totals = $components->reduce(fn (array $carry, ProjectComponent $component): array => [
            'attention_total' => $carry['attention_total'] + $this->metric($component, 'attention_total'),
            'accounts_expiring_soon' => $carry['accounts_expiring_soon'] + $this->metric($component, 'accounts_expiring_soon'),
            'unhealthy_proxies' => $carry['unhealthy_proxies'] + $this->metric($component, 'unhealthy_proxies'),
            'slow_proxies' => $carry['slow_proxies'] + $this->metric($component, 'slow_proxies'),
            'healthy_proxies' => $carry['healthy_proxies'] + $this->metric($component, 'healthy_proxies'),
        ], [
            'attention_total' => 0,
            'accounts_expiring_soon' => 0,
            'unhealthy_proxies' => 0,
            'slow_proxies' => 0,
            'healthy_proxies' => 0,
        ]);

        $failingPools = $components
            ->whereIn('current_status', ['warning', 'danger'])
            ->count();

        return [
            Stat::make('Proxy Pools', $components->count())
                ->description($failingPools.' need attention')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color($failingPools > 0 ? 'warning' : 'success'),

            Stat::make('Proxy Items To Review', $totals['attention_total'])
                ->description($totals['accounts_expiring_soon'].' renewals, '.$totals['unhealthy_proxies'].' unhealthy, '.$totals['slow_proxies'].' slow')
                ->descriptionIcon($totals['attention_total'] > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-shield-check')
                ->color($totals['unhealthy_proxies'] > 0 ? 'danger' : ($totals['attention_total'] > 0 ? 'warning' : 'success')),

            Stat::make('Healthy Proxies', $totals['healthy_proxies'])
                ->description('Currently reported healthy')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($totals['healthy_proxies'] > 0 ? 'success' : 'gray'),
        ];
    }

    private function metric(ProjectComponent $component, string $key): int
    {
        return max(0, (int) ($component->metrics[$key] ?? 0));
    }
}
