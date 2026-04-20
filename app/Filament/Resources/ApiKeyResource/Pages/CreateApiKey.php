<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Models\ApiKey;
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

    protected function afterCreate(): void
    {
        session()->flash(
            'api_key_plain_text',
            $this->generatedKey ?? throw new \LogicException('API key reveal requested before a key was generated.'),
        );
        session()->flash('api_key_name', $this->record?->name);

        $this->generatedKey = null;
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
