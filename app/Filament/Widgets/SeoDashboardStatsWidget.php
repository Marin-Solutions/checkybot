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
        $totalWebsites = Website::count();
        $totalChecks = SeoCheck::count();
        $completedChecks = SeoCheck::where('status', 'completed')->count();
        $runningChecks = SeoCheck::where('status', 'running')->count();

        $totalIssues = SeoIssue::count();
        $criticalIssues = SeoIssue::where('severity', 'error')->count();
        $warningIssues = SeoIssue::where('severity', 'warning')->count();

        $avgHealthScore = SeoCheck::where('status', 'completed')
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
