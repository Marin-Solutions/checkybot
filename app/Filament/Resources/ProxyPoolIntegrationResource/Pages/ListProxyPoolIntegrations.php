<?php

namespace App\Filament\Resources\ProxyPoolIntegrationResource\Pages;

use App\Filament\Resources\ProxyPoolIntegrationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProxyPoolIntegrations extends ListRecords
{
    protected static string $resource = ProxyPoolIntegrationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add proxy pool'),
        ];
    }
}
