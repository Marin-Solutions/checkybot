<?php

namespace App\Filament\Widgets;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ApiHealthStatsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $userId = auth()->id();

        $apiScope = MonitorApis::query()->where('created_by', $userId);
        $totalApis = (clone $apiScope)->count();

        if ($totalApis === 0) {
            return [
                Stat::make('API Monitors', 0)
                    ->description('No APIs configured')
                    ->descriptionIcon('heroicon-m-globe-alt')
                    ->color('gray'),
            ];
        }

        $apiIds = (clone $apiScope)->select('id');

        $latestResultAggregates = MonitorApiResult::query()
            ->whereIn('id', function ($query) use ($apiIds) {
                $query->from('monitor_api_results')
                    ->selectRaw('MAX(id)')
                    ->whereIn('monitor_api_id', $apiIds)
                    ->groupBy('monitor_api_id');
            })
            ->selectRaw('COUNT(*) as latest_count')
            ->selectRaw('SUM(CASE WHEN is_success = 1 THEN 1 ELSE 0 END) as healthy_apis')
            ->selectRaw('SUM(CASE WHEN is_success = 0 THEN 1 ELSE 0 END) as failing_apis')
            ->selectRaw('AVG(CASE WHEN is_success = 1 THEN response_time_ms END) as avg_response_time')
            ->first();

        $healthyApis = (int) ($latestResultAggregates?->healthy_apis ?? 0);
        $failingApis = (int) ($latestResultAggregates?->failing_apis ?? 0);
        $latestResultsCount = (int) ($latestResultAggregates?->latest_count ?? 0);
        $noDataApis = max(0, $totalApis - $latestResultsCount);

        $avgResponseTime = $latestResultAggregates?->avg_response_time
            ? (float) $latestResultAggregates->avg_response_time
            : null;

        $uptimeAggregates = MonitorApiResult::query()
            ->whereIn('monitor_api_id', (clone $apiScope)->select('id'))
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('COUNT(*) as total_results')
            ->selectRaw('SUM(CASE WHEN is_success = 1 THEN 1 ELSE 0 END) as success_results')
            ->first();

        $totalResults24h = (int) ($uptimeAggregates?->total_results ?? 0);
        $successResults24h = (int) ($uptimeAggregates?->success_results ?? 0);

        $uptimePercentage = $totalResults24h > 0
            ? round(($successResults24h / $totalResults24h) * 100, 1)
            : null;

        return [
            Stat::make('API Monitors', $totalApis)
                ->description($healthyApis.' healthy, '.$failingApis.' failing')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color($failingApis > 0 ? 'danger' : 'primary'),

            Stat::make('Healthy', $healthyApis)
                ->description('APIs responding correctly')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),

            Stat::make('Failing', $failingApis + $noDataApis)
                ->description($failingApis.' errors, '.$noDataApis.' no data')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($failingApis > 0 ? 'danger' : ($noDataApis > 0 ? 'warning' : 'gray')),

            Stat::make('24h Uptime', $uptimePercentage !== null ? $uptimePercentage.'%' : 'N/A')
                ->description('Avg response: '.($avgResponseTime ? round($avgResponseTime).'ms' : 'N/A'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($uptimePercentage >= 99 ? 'success' : ($uptimePercentage >= 95 ? 'warning' : 'danger')),
        ];
    }
}
