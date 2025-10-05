<?php

namespace App\Filament\Resources\WebsiteSeoCheckResource\Pages;

use App\Filament\Resources\WebsiteSeoCheckResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWebsiteSeoCheck extends EditRecord
{
    protected static string $resource = WebsiteSeoCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
