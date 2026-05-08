<?php

namespace App\Filament\Resources\BackupRemoteStorageResource\Pages;

use App\Filament\Resources\BackupRemoteStorageResource;
use App\Models\BackupRemoteStorageConfig;
use App\Models\BackupRemoteStorageType;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateBackupRemoteStorage extends CreateRecord
{
    protected static string $resource = BackupRemoteStorageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $typeId = $data['backup_remote_storage_type_id'] ?? null;
        $data['created_by'] = auth()->id();

        if (BackupRemoteStorageType::usesFileTransferFieldsForId($typeId)) {
            unset($data['access_key'], $data['secret_key'], $data['bucket'], $data['region'], $data['endpoint']);
        }

        if (BackupRemoteStorageType::usesS3FieldsForId($typeId)) {
            unset($data['host'], $data['port'], $data['username'], $data['password'], $data['directory']);
        }

        if (! BackupRemoteStorageType::requiresEndpointForId($typeId)) {
            unset($data['endpoint']);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }

    public function beforeCreate(): void
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
