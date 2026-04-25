<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\Concerns\HasHealthStatusTabs;
use App\Filament\Resources\WebsiteResource;
use App\Models\Website;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

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

    protected function disabledColumn(): string
    {
        return 'uptime_check';
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
