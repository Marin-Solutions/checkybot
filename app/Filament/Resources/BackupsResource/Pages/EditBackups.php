<?php

namespace App\Filament\Resources\BackupsResource\Pages;

use App\Filament\Resources\BackupsResource;
use Filament\Actions;
use Filament\Notifications\Notification;
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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['password'] ?? null) !== ($data['confirm_password'] ?? null)) {
            Notification::make()
                ->title('Passwords do not match')
                ->body('Please confirm your password.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }

        unset($data['confirm_password']);

        return $data;
    }
}
