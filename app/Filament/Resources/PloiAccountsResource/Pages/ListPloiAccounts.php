<?php

namespace App\Filament\Resources\PloiAccountsResource\Pages;

use App\Filament\Resources\PloiAccountsResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPloiAccounts extends ListRecords
{
    protected static string $resource = PloiAccountsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Add Ploi Account')
        ];
    }
}
