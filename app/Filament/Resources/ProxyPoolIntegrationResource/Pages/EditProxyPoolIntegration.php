<?php

namespace App\Filament\Resources\ProxyPoolIntegrationResource\Pages;

use App\Filament\Resources\ProxyPoolIntegrationResource;
use App\Filament\Resources\Support\ValidatesProjectAssignment;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProxyPoolIntegration extends EditRecord
{
    use ValidatesProjectAssignment;

    protected static string $resource = ProxyPoolIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->validateProjectAssignment($data['project_id'] ?? null);

        $tokenChanged = array_key_exists('token', $data)
            && filled($data['token'])
            && $this->record->token !== $data['token'];

        if (
            $this->record->base_url !== $data['base_url']
            || $tokenChanged
        ) {
            $data['last_sync_status'] = null;
            $data['last_sync_error'] = null;
            $data['last_synced_at'] = null;
        }

        return $data;
    }
}
