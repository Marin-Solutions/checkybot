<?php

namespace App\Filament\Resources\ProxyPoolIntegrationResource\Pages;

use App\Filament\Resources\ProxyPoolIntegrationResource;
use App\Filament\Resources\Support\ValidatesProjectAssignment;
use Filament\Resources\Pages\CreateRecord;

class CreateProxyPoolIntegration extends CreateRecord
{
    use ValidatesProjectAssignment;

    protected static string $resource = ProxyPoolIntegrationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->validateProjectAssignment($data['project_id'] ?? null);

        $data['created_by'] = auth()->id();

        return $data;
    }
}
