<?php

namespace App\Filament\Resources\WebsiteSeoCheckResource\Pages;

use App\Filament\Resources\WebsiteSeoCheckResource;
use Filament\Resources\Pages\ListRecords;

class ListWebsiteSeoChecks extends ListRecords
{
    protected static string $resource = WebsiteSeoCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
