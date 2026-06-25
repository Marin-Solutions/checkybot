<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\Concerns\HasHealthStatusTabs;
use App\Filament\Resources\WebsiteResource;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;

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
        $latestScheduledLogIds = WebsiteLogHistory::query()
            ->selectRaw('website_id, MAX(id) as max_id')
            ->where('is_on_demand', false)
            ->groupBy('website_id');

        return Website::query()
            ->select('websites.*')
            ->leftJoinSub($latestScheduledLogIds, 'latest_scheduled_log_ids', function ($join): void {
                $join->on('websites.id', '=', 'latest_scheduled_log_ids.website_id');
            })
            ->leftJoin('website_log_history as latest_scheduled_log', 'latest_scheduled_log.id', '=', 'latest_scheduled_log_ids.max_id')
            ->addSelect([
                DB::raw('latest_scheduled_log.id as latest_scheduled_log_id'),
                DB::raw('latest_scheduled_log.status as latest_scheduled_status'),
                DB::raw('latest_scheduled_log.summary as latest_scheduled_summary'),
                DB::raw('latest_scheduled_log.transport_error_type as latest_scheduled_transport_error_type'),
                DB::raw('latest_scheduled_log.http_status_code as latest_scheduled_http_status_code'),
                DB::raw('latest_scheduled_log.created_at as latest_scheduled_created_at'),
                DB::raw('latest_scheduled_log.is_on_demand as latest_scheduled_is_on_demand'),
            ])
            ->withCount([
                'globalNotifications as global_notifications_count',
                'individualNotifications as individual_notifications_count',
            ])
            ->with([
                'user:id,name',
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
