<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use Filament\Actions;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\WebsiteResource;

class CreateWebsite extends CreateRecord
{
    protected static string $resource = WebsiteResource::class;

    protected  function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();
        // $website
        $data['created_by'] =$user->id;
        return $data;

    }
}
