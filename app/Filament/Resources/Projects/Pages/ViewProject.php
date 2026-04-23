<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\ApiKey;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public ?string $guidedSetupApiKey = null;

    public ?string $guidedSetupApiKeyName = null;

    public function dismissGuidedSetupApiKey(): void
    {
        $this->guidedSetupApiKey = null;
        $this->guidedSetupApiKeyName = null;
    }

    public function issueGuidedSetupApiKey(array $data): ApiKey
    {
        throw_unless(ApiKeyResource::canManageApiKeys(), new HttpException(403));

        $apiKey = ApiKey::issueForUser(auth()->id(), $data);

        $this->guidedSetupApiKey = $apiKey->key;
        $this->guidedSetupApiKeyName = $apiKey->name;

        return $apiKey;
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
