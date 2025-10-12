<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use App\Models\Website;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateWebsite extends CreateRecord
{
    protected static string $resource = WebsiteResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->previousUrl;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        $data['created_by'] = $user->id;
        $sslExpiryDate = Website::sslExpiryDate($data['url']);
        $data['ssl_expiry_date'] = $sslExpiryDate;

        return $data;

    }

    protected function beforeCreate(): void
    {
        \App\Services\WebsiteUrlValidator::validate(
            $this->data['url'],
            fn () => $this->halt()
        );
    }
}
