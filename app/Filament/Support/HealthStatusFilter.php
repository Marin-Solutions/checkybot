<?php

namespace App\Filament\Support;

use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared factory for the "current status" health filters used on monitoring
 * list pages (Websites, API Monitors, Application Components). Centralising
 * the query logic keeps the Healthy/Warning/Danger/Unknown semantics in sync
 * across resources — including the dual representation of "unknown" as
 * either a NULL column or the literal string 'unknown' that services like
 * PackageSyncService and CheckybotControlService persist when disabling
 * checks.
 */
class HealthStatusFilter
{
    /**
     * Build a SelectFilter scoped to a nullable `current_status` column
     * (websites, monitor_apis). "Unknown" matches both NULL and the literal
     * 'unknown' string written by package sync / control flows.
     */
    public static function make(string $column = 'current_status'): SelectFilter
    {
        return SelectFilter::make($column)
            ->label('Current Status')
            ->options([
                'healthy' => 'Healthy',
                'warning' => 'Warning',
                'danger' => 'Danger',
                'unknown' => 'Unknown',
            ])
            ->query(function (Builder $query, array $data) use ($column): Builder {
                $value = $data['value'] ?? null;

                if ($value === null || $value === '') {
                    return $query;
                }

                if ($value === 'unknown') {
                    return $query->where(fn (Builder $inner) => $inner
                        ->whereNull($column)
                        ->orWhere($column, 'unknown'));
                }

                return $query->where($column, $value);
            });
    }

    /**
     * Build a SelectFilter for a non-nullable `current_status` column
     * (project_components). The Unknown option is omitted because the column
     * is constrained NOT NULL and no code path writes the 'unknown' literal,
     * so offering it would surface a filter that can never return records.
     */
    public static function makeForNonNullableColumn(string $column = 'current_status'): SelectFilter
    {
        return SelectFilter::make($column)
            ->label('Current Status')
            ->options([
                'healthy' => 'Healthy',
                'warning' => 'Warning',
                'danger' => 'Danger',
            ])
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
     * danger — the "show me only what is broken right now" shortcut.
     */
    public static function onlyFailing(string $column = 'current_status'): Filter
    {
        return Filter::make('only_failing')
            ->label('Show only failing')
            ->toggle()
            ->query(fn (Builder $query): Builder => $query->whereIn($column, ['warning', 'danger']));
    }
}
