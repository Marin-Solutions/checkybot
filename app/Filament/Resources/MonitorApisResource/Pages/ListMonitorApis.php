<?php

namespace App\Filament\Resources\MonitorApisResource\Pages;

use App\Filament\Resources\Concerns\HasHealthStatusTabs;
use App\Filament\Resources\MonitorApisResource;
use App\Models\MonitorApis;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListMonitorApis extends ListRecords
{
    use HasHealthStatusTabs;

    protected static string $resource = MonitorApisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New API'),
        ];
    }

    protected function disabledColumn(): string
    {
        return 'is_enabled';
    }

    protected function historyTable(): string
    {
        return 'monitor_api_results';
    }

    protected function historyForeignKey(): string
    {
        return 'monitor_api_id';
    }

    protected function ownerTable(): string
    {
        return 'monitor_apis';
    }

    /**
     * Lean count query: scoped to the current user, soft-delete aware, and
     * free of the eager loads / aggregates that {@see MonitorApisResource::getEloquentQuery()}
     * adds for the table view (those are dead weight inside `count(*)`).
     */
    protected function tabCountBaseQuery(): Builder
    {
        return MonitorApis::query()->where('created_by', auth()->id());
    }
}
