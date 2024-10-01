<?php

namespace App\Filament\Resources\NotificationChannelsResource\Pages;

use App\Filament\Resources\NotificationChannelsResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListNotificationChannels extends ListRecords
{
    protected static string $resource = NotificationChannelsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
