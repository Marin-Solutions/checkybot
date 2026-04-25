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

        if ((clone $websiteScope)->doesntExist()) {
            return [
                Stat::make('SSL monitoring', 0)
                    ->description('No websites with SSL monitoring enabled')
                    ->descriptionIcon('heroicon-m-shield-check')
                    ->color('gray'),
            ];
        }

        $today = now()->toDateString();
        $in7Days = now()->addDays(7)->toDateString();
        $in14Days = now()->addDays(14)->toDateString();
        $in30Days = now()->addDays(30)->toDateString();

        $aggregates = (clone $websiteScope)
            ->selectRaw('SUM(CASE WHEN ssl_expiry_date < ? THEN 1 ELSE 0 END) as expired_count', [$today])
            ->selectRaw('SUM(CASE WHEN ssl_expiry_date >= ? AND ssl_expiry_date <= ? THEN 1 ELSE 0 END) as within_7_count', [$today, $in7Days])
            ->selectRaw('SUM(CASE WHEN ssl_expiry_date >= ? AND ssl_expiry_date <= ? THEN 1 ELSE 0 END) as within_14_count', [$today, $in14Days])
            ->selectRaw('SUM(CASE WHEN ssl_expiry_date >= ? AND ssl_expiry_date <= ? THEN 1 ELSE 0 END) as within_30_count', [$today, $in30Days])
            ->first();

        $expiredCount = (int) ($aggregates?->expired_count ?? 0);
        $within7DaysCount = (int) ($aggregates?->within_7_count ?? 0);
        $within14DaysCount = (int) ($aggregates?->within_14_count ?? 0);
        $within30DaysCount = (int) ($aggregates?->within_30_count ?? 0);

        return [
            Stat::make('SSL expired', $expiredCount)
                ->description($expiredCount > 0 ? 'Renew immediately' : 'No expired certificates')
                ->descriptionIcon($expiredCount > 0 ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
                ->color($expiredCount > 0 ? 'danger' : 'success'),

            Stat::make('Expiring within 7 days', $within7DaysCount)
                ->description($within7DaysCount > 0 ? 'Action required' : 'Nothing expiring this week')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($within7DaysCount > 0 ? 'danger' : 'success'),

            Stat::make('Expiring within 14 days', $within14DaysCount)
                ->description('Includes the 7-day window')
                ->descriptionIcon('heroicon-m-clock')
                ->color($within14DaysCount > 0 ? 'warning' : 'success'),

            Stat::make('Expiring within 30 days', $within30DaysCount)
                ->description('Plan renewals for this month')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($within30DaysCount > 0 ? 'warning' : 'success'),
        ];
    }
}
