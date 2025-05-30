<?php

namespace App\Filament\Resources\WebsiteResource\Pages;


use Filament\Actions;
use App\Models\Website;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\WebsiteResource;
use GuzzleHttp\Exception\RequestException;

class CreateWebsite extends CreateRecord
{
    protected static string $resource = WebsiteResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->previousUrl;
    }


    protected  function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        $data['created_by'] =$user->id;
        $sslExpiryDate = Website::sslExpiryDate($data['url']);
        $data['ssl_expiry_date'] =$sslExpiryDate;

        return $data;

    }

    protected function beforeCreate(): void
    {
        \App\Services\WebsiteUrlValidator::validate(
            $this->data['url'],
            fn() => $this->halt()
        );
    }
}
