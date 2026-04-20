<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListApiKeys extends ListRecords
{
    protected static string $resource = ApiKeyResource::class;

    public ?string $generatedKey = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->createAnother(false)
                ->using(function (array $data): ApiKey {
                    $data['user_id'] = auth()->id();
                    $data['key'] = ApiKey::generateKey();
                    $this->generatedKey = $data['key'];

                    return ApiKey::create($data);
                })
                ->successNotification(function () {
                    $notification = ApiKeyResource::apiKeyCreatedNotification(
                        $this->generatedKey ?? throw new \LogicException('API key notification requested before a key was generated.'),
                    );

                    $this->generatedKey = null;

                    return $notification;
                }),
        ];
    }
}
