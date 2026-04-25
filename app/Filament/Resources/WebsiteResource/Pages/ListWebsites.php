<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ListWebsites extends ListRecords
{
    protected static string $resource = WebsiteResource::class;

    /**
     * Statuses that mark a website as currently failing for the tab filters.
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
            Actions\CreateAction::make(),
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
        return $query->where('uptime_check', false);
    }

    protected function scopeRecentlyRecovered(Builder $query): Builder
    {
        return $query
            ->where('current_status', 'healthy')
            ->whereExists(fn ($subquery) => $subquery
                ->select(DB::raw(1))
                ->from('website_log_history')
                ->whereColumn('website_log_history.website_id', 'websites.id')
                ->whereIn('website_log_history.status', self::UNHEALTHY_STATUSES)
                ->where('website_log_history.created_at', '>=', $this->recoveryWindowStart()));
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

        $base = WebsiteResource::getEloquentQuery();

        return $this->cachedTabCounts = [
            'failing' => (clone $base)->whereIn('current_status', self::UNHEALTHY_STATUSES)->count(),
            'disabled' => (clone $base)->where('uptime_check', false)->count(),
            'recently_recovered' => $this->scopeRecentlyRecovered(clone $base)->count(),
        ];
    }
}
