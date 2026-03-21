<?php

namespace App\Filament\Resources\ProjectComponents\Pages;

use App\Filament\Resources\ProjectComponents\ProjectComponentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProjectComponent extends ViewRecord
{
    protected static string $resource = ProjectComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
