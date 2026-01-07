<?php

namespace App\Filament\Widgets;

use App\Models\SeoCheck;
use App\Models\SeoIssue;
use App\Models\Website;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SeoDashboardStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $userId = auth()->id();

        $totalWebsites = Website::where('created_by', $userId)->count();
        $userWebsiteIds = Website::where('created_by', $userId)->pluck('id');

        $totalChecks = SeoCheck::whereIn('website_id', $userWebsiteIds)->count();
        $completedChecks = SeoCheck::whereIn('website_id', $userWebsiteIds)->where('status', 'completed')->count();
        $runningChecks = SeoCheck::whereIn('website_id', $userWebsiteIds)->where('status', 'running')->count();

        $userSeoCheckIds = SeoCheck::whereIn('website_id', $userWebsiteIds)->pluck('id');
        $totalIssues = SeoIssue::whereIn('seo_check_id', $userSeoCheckIds)->count();
        $criticalIssues = SeoIssue::whereIn('seo_check_id', $userSeoCheckIds)->where('severity', 'error')->count();
        $warningIssues = SeoIssue::whereIn('seo_check_id', $userSeoCheckIds)->where('severity', 'warning')->count();

        $avgHealthScore = SeoCheck::whereIn('website_id', $userWebsiteIds)
            ->where('status', 'completed')
            ->whereNotNull('computed_health_score')
            ->avg('computed_health_score');

        return [
            Stat::make('Total Websites', $totalWebsites)
                ->description('Websites being monitored')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('primary'),

            Stat::make('SEO Checks Run', $totalChecks)
                ->description($completedChecks.' completed, '.$runningChecks.' running')
                ->descriptionIcon('heroicon-m-magnifying-glass')
                ->color('info'),

            Stat::make('Average Health Score', $avgHealthScore ? number_format($avgHealthScore, 1).'%' : 'N/A')
                ->description('Across all completed checks')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($avgHealthScore >= 80 ? 'success' : ($avgHealthScore >= 60 ? 'warning' : 'danger')),

            Stat::make('Total Issues Found', $totalIssues)
                ->description($criticalIssues.' critical, '.$warningIssues.' warnings')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($criticalIssues > 0 ? 'danger' : ($warningIssues > 0 ? 'warning' : 'success')),
        ];
    }
}
