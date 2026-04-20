<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateApiKey extends CreateRecord
{
    protected static string $resource = ApiKeyResource::class;

    public ?string $generatedKey = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['key'] = ApiKey::generateKey();
        $this->generatedKey = $data['key'];

        return $data;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return ApiKeyResource::apiKeyCreatedNotification($this->generatedKey);
    }
}
