<?php

namespace App\Filament\Support;

use App\Support\HealthStatusLabel;
use Closure;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared factory for the "current status" health filters used on monitoring
 * list pages (Websites, API Monitors, Application Components). Centralising
 * the query logic keeps the Healthy/Warning/Failing/Pending semantics in sync
 * across resources — including the dual representation of "pending" as
 * either a NULL column or the literal string 'unknown' that services like
 * PackageSyncService and CheckybotControlService persist when disabling
 * checks.
 */
class HealthStatusFilter
{
    /**
     * Build a SelectFilter scoped to a nullable `current_status` column
     * (websites, monitor_apis). "Pending" matches NULL, 'unknown', and 'pending'
     * 'unknown' string written by package sync / control flows.
     */
    public static function make(string $column = 'current_status'): SelectFilter
    {
        return SelectFilter::make($column)
            ->label('Current Status')
            ->options([
                'healthy' => 'Healthy',
                'warning' => 'Warning',
                'danger' => 'Failing',
                'unknown' => 'Pending',
            ])
            ->query(function (Builder $query, array $data) use ($column): Builder {
                $value = $data['value'] ?? null;

                if ($value === null || $value === '') {
                    return $query;
                }

                if ($value === 'unknown') {
                    return $query->where(fn (Builder $inner) => $inner
                        ->whereNull($column)
                        ->orWhere($column, 'unknown')
                        ->orWhere($column, 'pending'));
                }

                return $query->where($column, $value);
            });
    }

    /**
     * Build a SelectFilter for a non-nullable `current_status` column
     * (project_components). "Pending" is used for components awaiting active
     * child check results.
     */
    public static function makeForNonNullableColumn(string $column = 'current_status'): SelectFilter
    {
        return SelectFilter::make($column)
            ->label('Current Status')
            ->options(HealthStatusLabel::options())
            ->query(function (Builder $query, array $data) use ($column): Builder {
                $value = $data['value'] ?? null;

                if ($value === null || $value === '') {
                    return $query;
                }

                return $query->where($column, $value);
            });
    }

    /**
     * Toggle that narrows the table to records currently in warning or
     * failing — the "show me only what is broken right now" shortcut.
     *
     * Disable flows for websites, API monitors, and components toggle
     * `uptime_check` / `is_enabled` / `is_archived` without normalising
     * `current_status`, so a failing status can linger on a paused row.
     * Callers pass an `$activeScope` closure that constrains the
     * query to records that are currently being checked, ensuring the
     * "only failing" shortcut surfaces a real triage list rather than
     * frozen historical state.
     */
    public static function onlyFailing(string $column = 'current_status', ?Closure $activeScope = null): Filter
    {
        return Filter::make('only_failing')
            ->label('Show only failing')
            ->toggle()
            ->query(function (Builder $query) use ($column, $activeScope): Builder {
                $query->whereIn($column, ['warning', 'danger']);

                if ($activeScope !== null) {
                    $query = $activeScope($query);
                }

                return $query;
            });
    }
}
