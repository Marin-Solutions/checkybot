<?php

namespace App\Filament\Widgets;

use App\Models\Website;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SslExpiryStatsWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $userId = auth()->id();

        $websiteScope = Website::query()
            ->where('created_by', $userId)
            ->where('ssl_check', true)
            ->whereNotNull('ssl_expiry_date');

        $today = now()->startOfDay()->toDateString();
        $in7Days = now()->startOfDay()->addDays(7)->toDateString();
        $in14Days = now()->startOfDay()->addDays(14)->toDateString();
        $in30Days = now()->startOfDay()->addDays(30)->toDateString();

        $expiredCount = (clone $websiteScope)
            ->whereDate('ssl_expiry_date', '<', $today)
            ->count();

        $within7DaysCount = (clone $websiteScope)
            ->whereDate('ssl_expiry_date', '>=', $today)
            ->whereDate('ssl_expiry_date', '<=', $in7Days)
            ->count();

        $within14DaysCount = (clone $websiteScope)
            ->whereDate('ssl_expiry_date', '>=', $today)
            ->whereDate('ssl_expiry_date', '<=', $in14Days)
            ->count();

        $within30DaysCount = (clone $websiteScope)
            ->whereDate('ssl_expiry_date', '>=', $today)
            ->whereDate('ssl_expiry_date', '<=', $in30Days)
            ->count();

        return [
            Stat::make('SSL expired', $expiredCount)
                ->description($expiredCount > 0 ? 'Renew immediately' : 'No expired certificates')
                ->descriptionIcon($expiredCount > 0 ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
                ->color($expiredCount > 0 ? 'danger' : 'success'),

            Stat::make('Expiring in 7 days', $within7DaysCount)
                ->description('Renew this week')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($within7DaysCount > 0 ? 'danger' : 'success'),

            Stat::make('Expiring in 14 days', $within14DaysCount)
                ->description('Includes the 7-day window')
                ->descriptionIcon('heroicon-m-clock')
                ->color($within14DaysCount > 0 ? 'warning' : 'success'),

            Stat::make('Expiring in 30 days', $within30DaysCount)
                ->description('Plan renewals for this month')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($within30DaysCount > 0 ? 'warning' : 'success'),
        ];
    }
}
