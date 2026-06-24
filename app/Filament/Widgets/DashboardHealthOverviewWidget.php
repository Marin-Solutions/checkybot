<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\HealthOverview;
use App\Services\DashboardHealthOverviewService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardHealthOverviewWidget extends BaseWidget
{
    /**
     * @var array<string>
     */
    public array $discoveredSchemaNames = [];

    public bool $areSchemaStateUpdateHooksDisabledForTesting = false;

    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $summary = app(DashboardHealthOverviewService::class)->summary((int) auth()->id());

        return [
            $this->stat('Green', $summary[DashboardHealthOverviewService::STATUS_HEALTHY], DashboardHealthOverviewService::STATUS_HEALTHY, 'success', 'heroicon-m-check-circle'),
            $this->stat('Warning', $summary[DashboardHealthOverviewService::STATUS_WARNING], DashboardHealthOverviewService::STATUS_WARNING, 'warning', 'heroicon-m-exclamation-triangle'),
            $this->stat('Critical', $summary[DashboardHealthOverviewService::STATUS_CRITICAL], DashboardHealthOverviewService::STATUS_CRITICAL, 'danger', 'heroicon-m-x-circle'),
        ];
    }

    /**
     * @param  array{count: int, percent: float}  $bucket
     */
    private function stat(string $label, array $bucket, string $status, string $color, string $icon): Stat
    {
        return Stat::make($label, number_format($bucket['count']))
            ->description($bucket['percent'].'% of monitored checks')
            ->descriptionIcon($icon)
            ->color($color)
            ->url(HealthOverview::getUrl(['status' => $status]));
    }
}
