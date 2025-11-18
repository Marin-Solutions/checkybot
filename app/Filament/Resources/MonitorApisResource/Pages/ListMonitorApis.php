<?php

namespace App\Filament\Resources\MonitorApisResource\Pages;

use App\Filament\Resources\MonitorApisResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMonitorApis extends ListRecords
{
    protected static string $resource = MonitorApisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New API'),
        ];
    }
}
