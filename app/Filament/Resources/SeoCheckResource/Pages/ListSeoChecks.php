<?php

namespace App\Filament\Resources\SeoCheckResource\Pages;

use App\Filament\Resources\SeoCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSeoChecks extends ListRecords
{
    protected static string $resource = SeoCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
