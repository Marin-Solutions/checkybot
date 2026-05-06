<?php

namespace App\Filament\Resources\BackupsResource\Pages;

use App\Filament\Resources\BackupsResource;
use App\Models\BackupRemoteStorageConfig;
use App\Models\Server;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $serverOwned = Server::query()
            ->whereKey($data['server_id'] ?? null)
            ->where('created_by', auth()->id())
            ->exists();

        $storageOwned = BackupRemoteStorageConfig::query()
            ->whereKey($data['remote_storage_id'] ?? null)
            ->where('created_by', auth()->id())
            ->exists();

        $errors = [];

        if (! $serverOwned) {
            $errors['server_id'] = 'Choose one of your own servers.';
        }

        if (! $storageOwned) {
            $errors['remote_storage_id'] = 'Choose one of your own remote storage configs.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }
}
