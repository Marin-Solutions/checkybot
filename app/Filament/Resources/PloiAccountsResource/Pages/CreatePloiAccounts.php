<?php

namespace App\Filament\Resources\PloiAccountsResource\Pages;

use App\Filament\Resources\PloiAccountsResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreatePloiAccounts extends CreateRecord
{
    protected static string $resource = PloiAccountsResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->previousUrl;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = Auth::id();

        return $data;
    }
}
