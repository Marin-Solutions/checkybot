<?php

namespace App\Filament\Resources\ProjectComponents\Pages;

use App\Filament\Resources\ProjectComponents\ProjectComponentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjectComponents extends ListRecords
{
    protected static string $resource = ProjectComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
