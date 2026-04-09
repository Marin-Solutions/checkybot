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

        $websiteScope = Website::query()->where('created_by', $userId);
        $totalWebsites = (clone $websiteScope)->count();
        $userWebsiteIds = (clone $websiteScope)->select('id');

        $checkStats = SeoCheck::query()
            ->whereIn('website_id', $userWebsiteIds)
            ->selectRaw('COUNT(*) as total_checks')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_checks")
            ->selectRaw("SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_checks")
            ->selectRaw("AVG(CASE WHEN status = 'completed' AND computed_health_score IS NOT NULL THEN computed_health_score END) as avg_health_score")
            ->first();

        $issueStats = SeoIssue::query()
            ->join('seo_checks', 'seo_checks.id', '=', 'seo_issues.seo_check_id')
            ->whereIn('seo_checks.website_id', $userWebsiteIds)
            ->selectRaw('COUNT(*) as total_issues')
            ->selectRaw("SUM(CASE WHEN seo_issues.severity = 'error' THEN 1 ELSE 0 END) as critical_issues")
            ->selectRaw("SUM(CASE WHEN seo_issues.severity = 'warning' THEN 1 ELSE 0 END) as warning_issues")
            ->first();

        $totalChecks = (int) ($checkStats?->total_checks ?? 0);
        $completedChecks = (int) ($checkStats?->completed_checks ?? 0);
        $runningChecks = (int) ($checkStats?->running_checks ?? 0);
        $avgHealthScore = $checkStats?->avg_health_score ? (float) $checkStats->avg_health_score : null;
        $totalIssues = (int) ($issueStats?->total_issues ?? 0);
        $criticalIssues = (int) ($issueStats?->critical_issues ?? 0);
        $warningIssues = (int) ($issueStats?->warning_issues ?? 0);

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
