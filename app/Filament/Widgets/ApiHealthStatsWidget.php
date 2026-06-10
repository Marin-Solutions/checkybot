<?php

namespace App\Filament\Widgets;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class ApiHealthStatsWidget extends BaseWidget
{
    /**
     * @var array<string>
     */
    public array $discoveredSchemaNames = [];

    public bool $areSchemaStateUpdateHooksDisabledForTesting = false;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $counts = $this->collectCounts();
        $totalApis = $counts['total'];

        if ($totalApis === 0) {
            return [
                Stat::make('API Monitors', 0)
                    ->description('No APIs configured')
                    ->descriptionIcon('heroicon-m-globe-alt')
                    ->color('gray'),
            ];
        }

        $activeMonitorsQuery = $this->monitorScope()
            ->where('is_enabled', true);

        $healthyApis = $counts['healthy'];
        $failingApis = $counts['failing'];
        $pendingApis = $counts['pending'];
        $avgResponseTime = $this->averageHealthyResponseTime(clone $activeMonitorsQuery);

        $uptimeAggregates = $this->activeScheduledResults(clone $activeMonitorsQuery)
            ->where('monitor_api_results.created_at', '>=', now()->subDay())
            ->selectRaw('COUNT(*) as total_results')
            ->selectRaw('SUM(CASE WHEN monitor_api_results.is_success = 1 THEN 1 ELSE 0 END) as success_results')
            ->first();

        $totalResults24h = (int) ($uptimeAggregates?->total_results ?? 0);
        $successResults24h = (int) ($uptimeAggregates?->success_results ?? 0);

        $uptimePercentage = $totalResults24h > 0
            ? round(($successResults24h / $totalResults24h) * 100, 1)
            : null;

        return [
            Stat::make('API Monitors', $totalApis)
                ->description($counts['enabled'].' enabled, '.$counts['disabled'].' disabled')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color($failingApis > 0 ? 'danger' : 'primary'),

            Stat::make('Healthy', $healthyApis)
                ->description('Enabled and passing scheduled checks')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($healthyApis > 0 ? 'success' : 'gray'),

            Stat::make('Failing', $failingApis)
                ->description($failingApis.' warning/failing, '.$pendingApis.' pending')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($failingApis > 0 ? 'danger' : ($pendingApis > 0 ? 'warning' : 'gray')),

            Stat::make('Pending', $pendingApis)
                ->description($pendingApis === 0 ? 'All enabled monitors have live status' : 'Awaiting first scheduled result')
                ->descriptionIcon('heroicon-m-clock')
                ->color($pendingApis > 0 ? 'warning' : 'success'),

            Stat::make('24h Uptime', $uptimePercentage !== null ? $uptimePercentage.'%' : 'N/A')
                ->description('Avg response: '.($avgResponseTime ? round($avgResponseTime).'ms' : 'N/A'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($uptimePercentage === null ? 'gray' : ($uptimePercentage >= 99 ? 'success' : ($uptimePercentage >= 95 ? 'warning' : 'danger'))),
        ];
    }

    /**
     * @return array{total: int, enabled: int, disabled: int, pending: int, healthy: int, failing: int}
     */
    public function collectCounts(): array
    {
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $scheduledVal = $driver === 'pgsql' ? 'false' : '0';

        $hasScheduledSql = "exists (
            select 1
            from monitor_api_results
            where monitor_api_results.monitor_api_id = monitor_apis.id
                and monitor_api_results.is_on_demand = {$scheduledVal}
        )";

        $aggregates = $this->monitorScope()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN monitor_apis.is_enabled = 1 THEN 1 ELSE 0 END) as enabled')
            ->selectRaw('SUM(CASE WHEN monitor_apis.is_enabled = 0 THEN 1 ELSE 0 END) as disabled')
            ->selectRaw("
                SUM(CASE
                    WHEN monitor_apis.is_enabled = 1
                        AND (
                            not {$hasScheduledSql}
                            OR monitor_apis.current_status IS NULL
                            OR monitor_apis.current_status NOT IN ('healthy', 'warning', 'danger')
                        )
                    THEN 1 ELSE 0
                END) as pending
            ")
            ->selectRaw("
                SUM(CASE
                    WHEN monitor_apis.is_enabled = 1
                        AND {$hasScheduledSql}
                        AND monitor_apis.current_status = 'healthy'
                    THEN 1 ELSE 0
                END) as healthy
            ")
            ->selectRaw("
                SUM(CASE
                    WHEN monitor_apis.is_enabled = 1
                        AND {$hasScheduledSql}
                        AND monitor_apis.current_status IN ('warning', 'danger')
                    THEN 1 ELSE 0
                END) as failing
            ")
            ->first();

        return [
            'total' => (int) ($aggregates?->total ?? 0),
            'enabled' => (int) ($aggregates?->enabled ?? 0),
            'disabled' => (int) ($aggregates?->disabled ?? 0),
            'pending' => (int) ($aggregates?->pending ?? 0),
            'healthy' => (int) ($aggregates?->healthy ?? 0),
            'failing' => (int) ($aggregates?->failing ?? 0),
        ];
    }

    /**
     * @param  Builder<MonitorApis>  $activeMonitorsQuery
     */
    private function averageHealthyResponseTime(Builder $activeMonitorsQuery): ?float
    {
        $average = MonitorApiResult::query()
            ->whereIn('monitor_api_results.id', $this->latestActiveScheduledResultIds($activeMonitorsQuery))
            ->where('monitor_api_results.is_success', true)
            ->average('monitor_api_results.response_time_ms');

        return $average === null ? null : (float) $average;
    }

    /**
     * @param  Builder<MonitorApis>  $activeMonitorsQuery
     */
    private function activeScheduledResults(Builder $activeMonitorsQuery): Builder
    {
        return MonitorApiResult::query()
            ->whereIn('monitor_api_results.monitor_api_id', $activeMonitorsQuery->select('id'))
            ->where('monitor_api_results.is_on_demand', false);
    }

    /**
     * @param  Builder<MonitorApis>  $activeMonitorsQuery
     */
    private function latestActiveScheduledResultIds(Builder $activeMonitorsQuery): Builder
    {
        return $this->activeScheduledResults($activeMonitorsQuery)
            ->selectRaw('MAX(monitor_api_results.id)')
            ->groupBy('monitor_api_results.monitor_api_id');
    }

    private function monitorScope(): Builder
    {
        return MonitorApis::query()
            ->where('monitor_apis.created_by', auth()->id());
    }
}
