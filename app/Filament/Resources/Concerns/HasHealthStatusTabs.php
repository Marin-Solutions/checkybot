<?php

namespace App\Filament\Resources\Concerns;

use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Adds a four-tab health status strip (All / Failing / Disabled /
 * Recently Recovered) to a Filament list page so triage is one click instead
 * of a sort-and-eyeball.
 *
 * Tabs are anchored to the model's `current_status` column, the resource's
 * "is enabled" flag (configurable via {@see disabledColumn()}), and a
 * recent-failure lookup against the resource's history table (configurable
 * via {@see historyTable()}, {@see historyForeignKey()}, and
 * {@see ownerTable()}).
 *
 * Each non-default tab exposes a count badge so the failing/disabled/recently
 * recovered totals are visible without switching tabs. Counts are memoized
 * on the page instance so a render performs at most three additional count
 * queries regardless of how many badge callbacks fire.
 *
 * Soft-deleted rows are excluded from both the badge counts and the filtered
 * results so the strip mirrors the default (non-trashed) table view, just like
 * {@see HasUnhealthyNavigationBadge}.
 */
trait HasHealthStatusTabs
{
    /**
     * Statuses considered failing for the "Failing" and "Recently Recovered"
     * tabs.
     */
    protected const UNHEALTHY_STATUSES = ['warning', 'danger'];

    /**
     * Number of hours that classifies a recovery as "recent" for the
     * "Recently Recovered" tab.
     */
    protected const RECOVERY_WINDOW_HOURS = 24;

    /**
     * In-instance cache for tab badge counts so each render performs at most
     * three count queries instead of one per badge callback invocation.
     *
     * @var array<string, int>|null
     */
    protected ?array $cachedTabCounts = null;

    /**
     * Column on the resource's model that is set to false when the user has
     * paused checks for that record.
     */
    abstract protected function disabledColumn(): string;

    /**
     * Database table that records each historical heartbeat or check result
     * for the resource (e.g. `website_log_history` or `monitor_api_results`).
     */
    abstract protected function historyTable(): string;

    /**
     * Foreign key column on the history table that points to the owner record.
     */
    abstract protected function historyForeignKey(): string;

    /**
     * Database table that the resource's model is stored in.
     */
    abstract protected function ownerTable(): string;

    /**
     * Lean base query used for tab badge counts. Defaults to a soft-delete
     * aware, user-scoped query against the resource's model. Override to
     * customize the scope.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>
     */
    abstract protected function tabCountBaseQuery(): Builder;

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
        return $query->where($this->disabledColumn(), false);
    }

    protected function scopeRecentlyRecovered(Builder $query): Builder
    {
        $historyTable = $this->historyTable();
        $foreignKey = $this->historyForeignKey();
        $ownerTable = $this->ownerTable();
        $since = $this->recoveryWindowStart();

        return $query
            ->where('current_status', 'healthy')
            ->whereExists(fn (QueryBuilder $subquery): QueryBuilder => $subquery
                ->select(DB::raw(1))
                ->from($historyTable)
                ->whereColumn("{$historyTable}.{$foreignKey}", "{$ownerTable}.id")
                ->whereIn("{$historyTable}.status", self::UNHEALTHY_STATUSES)
                ->where("{$historyTable}.created_at", '>=', $since));
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

        $base = $this->tabCountBaseQuery();

        return $this->cachedTabCounts = [
            'failing' => $this->scopeFailing(clone $base)->count(),
            'disabled' => $this->scopeDisabled(clone $base)->count(),
            'recently_recovered' => $this->scopeRecentlyRecovered(clone $base)->count(),
        ];
    }
}
