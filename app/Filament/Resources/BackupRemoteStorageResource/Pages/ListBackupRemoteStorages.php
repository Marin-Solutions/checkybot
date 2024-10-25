<?php

namespace App\Filament\Resources\BackupRemoteStorageResource\Pages;

use App\Filament\Resources\BackupRemoteStorageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBackupRemoteStorages extends ListRecords
{
    protected static string $resource = BackupRemoteStorageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
