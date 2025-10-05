<?php

namespace App\Filament\Resources\WebsiteSeoCheckResource\Pages;

use App\Filament\Resources\WebsiteSeoCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListWebsiteSeoChecks extends ListRecords
{
    protected static string $resource = WebsiteSeoCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()
            ->with(['latestSeoCheck'])
            ->has('seoChecks'); // Only show websites that have SEO checks
    }
}
