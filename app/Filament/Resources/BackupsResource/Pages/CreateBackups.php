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

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! BackupsResource::ownsSelectedReferences($data)) {
            Notification::make()
                ->title('Backup destination is not available')
                ->body('Choose one of your servers and remote storage configs.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }

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
        $data['created_by'] = auth()->id();

        return $data;
    }
}
