<?php

namespace App\Filament\Widgets;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class ApiHealthStatsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $userId = auth()->id();

        $totalApis = MonitorApis::where('created_by', $userId)->count();

        if ($totalApis === 0) {
            return [
                Stat::make('API Monitors', 0)
                    ->description('No APIs configured')
                    ->descriptionIcon('heroicon-m-globe-alt')
                    ->color('gray'),
            ];
        }

        $apiIds = MonitorApis::where('created_by', $userId)->pluck('id');

        $latestResults = MonitorApiResult::query()
            ->whereIn('monitor_api_id', $apiIds)
            ->whereIn('id', function ($query) use ($apiIds) {
                $query->select(DB::raw('MAX(id)'))
                    ->from('monitor_api_results')
                    ->whereIn('monitor_api_id', $apiIds)
                    ->groupBy('monitor_api_id');
            })
            ->get();

        $healthyApis = $latestResults->where('is_success', true)->count();
        $failingApis = $latestResults->where('is_success', false)->count();
        $noDataApis = $totalApis - $latestResults->count();

        $avgResponseTime = $latestResults->where('is_success', true)->avg('response_time_ms');

        $last24hResults = MonitorApiResult::query()
            ->whereIn('monitor_api_id', $apiIds)
            ->where('created_at', '>=', now()->subDay())
            ->get();

        $uptimePercentage = $last24hResults->count() > 0
            ? round(($last24hResults->where('is_success', true)->count() / $last24hResults->count()) * 100, 1)
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
