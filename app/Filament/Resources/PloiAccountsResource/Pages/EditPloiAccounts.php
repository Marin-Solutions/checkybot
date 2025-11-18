<?php

namespace App\Filament\Resources\PloiAccountsResource\Pages;

use App\Filament\Resources\PloiAccountsResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPloiAccounts extends EditRecord
{
    protected static string $resource = PloiAccountsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->previousUrl;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->record->key !== $data['key']) {
            $data['is_verified'] = false;
            $data['error_message'] = 'API Key was changed and needs to be re-verified.';
        }

        return $data;
    }

    protected function getAllRelationManagers(): array
    {
        return [];
    }
}
