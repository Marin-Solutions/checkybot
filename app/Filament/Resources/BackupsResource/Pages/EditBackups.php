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

    protected function beforeSave(): void
    {
        $password = $this->data['password'] ?? null;
        $confirmPassword = $this->data['confirm_password'] ?? null;
        $storedPassword = $this->record->getAttribute('password');

        if ($password !== $storedPassword && $password !== $confirmPassword) {
            Notification::make()
                ->title('Passwords do not match')
                ->body('Please confirm your password.')
                ->danger()
                ->persistent()
                ->send();

            $this->halt();
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        BackupsResource::validateSelectedReferences($data);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        unset($data['confirm_password']);
        $data['created_by'] = $this->record->created_by ?? auth()->id();

        return $data;
    }
}
