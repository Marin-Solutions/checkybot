<?php

namespace App\Filament\Resources\BackupsResource\Pages;

use App\Filament\Resources\BackupsResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBackups extends EditRecord
{
    protected static string $resource = BackupsResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
