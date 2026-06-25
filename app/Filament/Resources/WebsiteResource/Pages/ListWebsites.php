<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\Concerns\HasHealthStatusTabs;
use App\Filament\Resources\WebsiteResource;
use App\Models\Website;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ListWebsites extends ListRecords
{
    use HasHealthStatusTabs;

    protected static string $resource = WebsiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableQuery(): Builder
    {
        return Website::query()
            ->withAvg('logHistoryLast24h as average_response_time', 'speed')
            ->withCount([
                'globalNotifications as global_notifications_count',
                'individualNotifications as individual_notifications_count',
            ])
            ->with([
                'user:id,name',
                'latestScheduledLogHistory',
                'latestDiagnosticLogHistory',
                'latestSeoCheck',
            ])
            ->where('created_by', auth()->id())
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    protected function disabledColumn(): string
    {
        // Required by HasHealthStatusTabs; WebsiteResource overrides the
        // disabled scope because websites have separate uptime and SSL checks.
        return 'uptime_check';
    }

    protected function scopeFailing(Builder $query): Builder
    {
        return WebsiteResource::scopeActiveMonitoring(
            $query->whereIn('current_status', self::UNHEALTHY_STATUSES)
        );
    }

    protected function scopeDisabled(Builder $query): Builder
    {
        return WebsiteResource::scopeDisabledMonitoring($query);
    }

    protected function historyTable(): string
    {
        return 'website_log_history';
    }

    protected function historyForeignKey(): string
    {
        return 'website_id';
    }

    protected function ownerTable(): string
    {
        return 'websites';
    }

    /**
     * Lean count query: scoped to the current user, soft-delete aware, and
     * free of the eager loads / aggregates that {@see WebsiteResource::getEloquentQuery()}
     * adds for the table view (those are dead weight inside `count(*)`).
     */
    protected function tabCountBaseQuery(): Builder
    {
        return Website::query()->where('created_by', auth()->id());
    }
}
