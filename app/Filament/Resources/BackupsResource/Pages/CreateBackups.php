<?php

namespace App\Filament\Resources\BackupsResource\Pages;

use App\Filament\Resources\BackupsResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateBackups extends CreateRecord
{
    protected static string $resource = BackupsResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }

    protected function beforeCreate(): void
    {
        if (! BackupsResource::ownsSelectedReferences($this->data)) {
            Notification::make()
                ->title('Backup destination is not available')
                ->body('Choose one of your servers and remote storage configs.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }

        if (($this->data['password'] ?? null) !== ($this->data['confirm_password'] ?? null)) {
            Notification::make()
                ->title('Passwords do not match')
                ->body('Please confirm your password.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['confirm_password']);
        $data['created_by'] = auth()->id();

        return $data;
    }
}
