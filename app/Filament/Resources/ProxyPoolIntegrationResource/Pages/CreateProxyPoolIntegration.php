<?php

namespace App\Filament\Resources\ProxyPoolIntegrationResource\Pages;

use App\Filament\Resources\ProxyPoolIntegrationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProxyPoolIntegration extends CreateRecord
{
    protected static string $resource = ProxyPoolIntegrationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
