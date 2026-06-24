<?php

namespace App\Filament\Pages;

use App\Services\DashboardHealthOverviewService;
use Filament\Pages\Page;

class HealthOverview extends Page
{
    protected string $view = 'filament.pages.health-overview';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-heart';

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Health Overview';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Health Overview';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['Super Admin', 'Admin']) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $service = app(DashboardHealthOverviewService::class);
        $status = $this->status();

        return [
            'activeStatus' => $status,
            'summary' => $service->summary((int) auth()->id()),
            'items' => $service->items((int) auth()->id(), $status === 'all' ? null : $status),
            'statusOptions' => [
                'all' => 'All',
                DashboardHealthOverviewService::STATUS_HEALTHY => 'Green',
                DashboardHealthOverviewService::STATUS_WARNING => 'Warning',
                DashboardHealthOverviewService::STATUS_CRITICAL => 'Critical',
            ],
        ];
    }

    private function status(): string
    {
        $status = request()->query('status', 'all');

        return in_array($status, [
            'all',
            DashboardHealthOverviewService::STATUS_HEALTHY,
            DashboardHealthOverviewService::STATUS_WARNING,
            DashboardHealthOverviewService::STATUS_CRITICAL,
        ], true) ? $status : 'all';
    }
}
