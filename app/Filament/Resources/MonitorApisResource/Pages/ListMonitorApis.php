<?php

namespace App\Filament\Resources\MonitorApisResource\Pages;

use App\Filament\Resources\MonitorApisResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ListMonitorApis extends ListRecords
{
    protected static string $resource = MonitorApisResource::class;

    /**
     * Statuses that mark an API monitor as currently failing for the tab filters.
     */
    protected const UNHEALTHY_STATUSES = ['warning', 'danger'];

    /**
     * Number of hours that classifies a recovery as "recent".
     */
    protected const RECOVERY_WINDOW_HOURS = 24;

    /**
     * In-instance cache for tab badge counts so each render does at most three
     * count queries instead of one per tab badge invocation.
     *
     * @var array<string, int>|null
     */
    protected ?array $cachedTabCounts = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New API'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All')
                ->icon('heroicon-o-list-bullet'),

            'failing' => Tab::make('Failing')
                ->icon('heroicon-o-exclamation-triangle')
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->scopeFailing($query))
                ->badge(fn (): ?int => $this->getTabCount('failing')),

            'disabled' => Tab::make('Disabled')
                ->icon('heroicon-o-pause-circle')
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->scopeDisabled($query))
                ->badge(fn (): ?int => $this->getTabCount('disabled')),

            'recently_recovered' => Tab::make('Recently Recovered')
                ->icon('heroicon-o-arrow-path')
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $this->scopeRecentlyRecovered($query))
                ->badge(fn (): ?int => $this->getTabCount('recently_recovered')),
        ];
    }

    protected function scopeFailing(Builder $query): Builder
    {
        return $query->whereIn('current_status', self::UNHEALTHY_STATUSES);
    }

    protected function scopeDisabled(Builder $query): Builder
    {
        return $query->where('is_enabled', false);
    }

    protected function scopeRecentlyRecovered(Builder $query): Builder
    {
        return $query
            ->where('current_status', 'healthy')
            ->whereExists(fn ($subquery) => $subquery
                ->select(DB::raw(1))
                ->from('monitor_api_results')
                ->whereColumn('monitor_api_results.monitor_api_id', 'monitor_apis.id')
                ->whereIn('monitor_api_results.status', self::UNHEALTHY_STATUSES)
                ->where('monitor_api_results.created_at', '>=', $this->recoveryWindowStart()));
    }

    protected function recoveryWindowStart(): Carbon
    {
        return now()->subHours(self::RECOVERY_WINDOW_HOURS);
    }

    protected function getTabCount(string $key): ?int
    {
        return $this->resolveTabCounts()[$key] ?? null;
    }

    /**
     * @return array<string, int>
     */
    protected function resolveTabCounts(): array
    {
        if ($this->cachedTabCounts !== null) {
            return $this->cachedTabCounts;
        }

        $base = MonitorApisResource::getEloquentQuery();

        return $this->cachedTabCounts = [
            'failing' => (clone $base)->whereIn('current_status', self::UNHEALTHY_STATUSES)->count(),
            'disabled' => (clone $base)->where('is_enabled', false)->count(),
            'recently_recovered' => $this->scopeRecentlyRecovered(clone $base)->count(),
        ];
    }
}
