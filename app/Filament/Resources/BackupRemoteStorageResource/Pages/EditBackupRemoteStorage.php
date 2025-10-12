<?php

namespace App\Filament\Resources\BackupRemoteStorageResource\Pages;

use App\Filament\Resources\BackupRemoteStorageResource;
use App\Models\BackupRemoteStorageConfig;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBackupRemoteStorage extends EditRecord
{
    protected static string $resource = BackupRemoteStorageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }

    public function beforeSave(): void
    {
        $testConnection = BackupRemoteStorageConfig::testConnection($this->data);

        if ($testConnection['error']) {
            Notification::make()
                ->{$testConnection['error'] ? 'danger' : 'success'}()
                ->title($testConnection['title'])
                ->body($testConnection['message'])
                ->send();

            $this->halt();
        }
    }
}
