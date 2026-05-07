<?php

namespace App\Filament\Widgets;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class ApiHealthStatsWidget extends BaseWidget
{
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

        $healthyApis = $counts['healthy'];
        $failingApis = $counts['failing'];
        $staleApis = $counts['stale'];
        $noDataApis = $counts['no_data'];
        $avgResponseTime = $this->averageHealthyResponseTime();

        $uptimeAggregates = $this->activeScheduledResults()
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
                ->description('Enabled, fresh and passing scheduled checks')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($healthyApis > 0 ? 'success' : 'gray'),

            Stat::make('Failing', $failingApis)
                ->description($failingApis.' warning/danger, '.$noDataApis.' no data')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($failingApis > 0 ? 'danger' : ($noDataApis > 0 ? 'warning' : 'gray')),

            Stat::make('Stale / No data', $staleApis + $noDataApis)
                ->description($staleApis.' stale, '.$noDataApis.' awaiting scheduled data')
                ->descriptionIcon('heroicon-m-clock')
                ->color(($staleApis + $noDataApis) > 0 ? 'warning' : 'success'),

            Stat::make('24h Uptime', $uptimePercentage !== null ? $uptimePercentage.'%' : 'N/A')
                ->description('Avg response: '.($avgResponseTime ? round($avgResponseTime).'ms' : 'N/A'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($uptimePercentage === null ? 'gray' : ($uptimePercentage >= 99 ? 'success' : ($uptimePercentage >= 95 ? 'warning' : 'danger'))),
        ];
    }

    /**
     * @return array{total: int, enabled: int, disabled: int, stale: int, no_data: int, healthy: int, failing: int}
     */
    public function collectCounts(): array
    {
        $scheduledResults = MonitorApiResult::query()
            ->scheduled()
            ->select('monitor_api_id')
            ->groupBy('monitor_api_id');

        $aggregates = MonitorApis::query()
            ->where('monitor_apis.created_by', auth()->id())
            ->leftJoinSub($scheduledResults, 'scheduled_results', function ($join): void {
                $join->on('scheduled_results.monitor_api_id', '=', 'monitor_apis.id');
            })
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN monitor_apis.is_enabled = 1 THEN 1 ELSE 0 END) as enabled')
            ->selectRaw('SUM(CASE WHEN monitor_apis.is_enabled = 0 THEN 1 ELSE 0 END) as disabled')
            ->selectRaw('SUM(CASE WHEN monitor_apis.is_enabled = 1 AND monitor_apis.stale_at IS NOT NULL THEN 1 ELSE 0 END) as stale')
            ->selectRaw("
                SUM(CASE
                    WHEN monitor_apis.is_enabled = 1
                        AND monitor_apis.stale_at IS NULL
                        AND (
                            scheduled_results.monitor_api_id IS NULL
                            OR monitor_apis.current_status IS NULL
                            OR monitor_apis.current_status NOT IN ('healthy', 'warning', 'danger')
                        )
                    THEN 1 ELSE 0
                END) as no_data
            ")
            ->selectRaw("
                SUM(CASE
                    WHEN monitor_apis.is_enabled = 1
                        AND monitor_apis.stale_at IS NULL
                        AND scheduled_results.monitor_api_id IS NOT NULL
                        AND monitor_apis.current_status = 'healthy'
                    THEN 1 ELSE 0
                END) as healthy
            ")
            ->selectRaw("
                SUM(CASE
                    WHEN monitor_apis.is_enabled = 1
                        AND monitor_apis.stale_at IS NULL
                        AND scheduled_results.monitor_api_id IS NOT NULL
                        AND monitor_apis.current_status IN ('warning', 'danger')
                    THEN 1 ELSE 0
                END) as failing
            ")
            ->first();

        return [
            'total' => (int) ($aggregates?->total ?? 0),
            'enabled' => (int) ($aggregates?->enabled ?? 0),
            'disabled' => (int) ($aggregates?->disabled ?? 0),
            'stale' => (int) ($aggregates?->stale ?? 0),
            'no_data' => (int) ($aggregates?->no_data ?? 0),
            'healthy' => (int) ($aggregates?->healthy ?? 0),
            'failing' => (int) ($aggregates?->failing ?? 0),
        ];
    }

    private function averageHealthyResponseTime(): ?float
    {
        $average = MonitorApiResult::query()
            ->whereIn('monitor_api_results.id', $this->latestActiveScheduledResultIds())
            ->where('monitor_api_results.is_success', true)
            ->average('monitor_api_results.response_time_ms');

        return $average === null ? null : (float) $average;
    }

    private function activeScheduledResults(): Builder
    {
        return MonitorApiResult::query()
            ->join('monitor_apis', 'monitor_apis.id', '=', 'monitor_api_results.monitor_api_id')
            ->where('monitor_apis.created_by', auth()->id())
            ->where('monitor_apis.is_enabled', true)
            ->whereNull('monitor_apis.stale_at')
            ->where('monitor_api_results.is_on_demand', false);
    }

    private function latestActiveScheduledResultIds(): Builder
    {
        return $this->activeScheduledResults()
            ->selectRaw('MAX(monitor_api_results.id)')
            ->groupBy('monitor_api_results.monitor_api_id');
    }
}
