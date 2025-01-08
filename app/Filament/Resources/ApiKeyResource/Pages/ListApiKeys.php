<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Models\ApiKey;

class ListApiKeys extends ListRecords
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->using(function (array $data): ApiKey {
                    $data['user_id'] = auth()->id();
                    $data['key'] = ApiKey::generateKey();
                    return ApiKey::create($data);
                }),
        ];
    }
} 