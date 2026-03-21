<?php

namespace App\Filament\Resources\ProjectComponents\Pages;

use App\Filament\Resources\ProjectComponents\ProjectComponentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProjectComponent extends EditRecord
{
    protected static string $resource = ProjectComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
