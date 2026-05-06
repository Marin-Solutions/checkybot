<?php

namespace App\Filament\Resources\BackupsResource\Pages;

use App\Filament\Resources\BackupsResource;
use App\Models\BackupRemoteStorageConfig;
use App\Models\Server;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateBackups extends CreateRecord
{
    protected static string $resource = BackupsResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($data['password'] !== $data['confirm_password']) {
            Notification::make()
                ->title('Passwords do not match')
                ->body('Please confirm your password.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }

        unset($data['confirm_password']);

        $this->validateOwnership($data);

        return $data;
    }

    protected function validateOwnership(array $data): void
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

        if ($errors === []) {
            return;
        }

        throw ValidationException::withMessages($errors);
    }
}
