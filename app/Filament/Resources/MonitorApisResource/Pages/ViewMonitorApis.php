<?php

namespace App\Filament\Resources\MonitorApisResource\Pages;

use App\Filament\Resources\MonitorApisResource;
use Filament\Resources\Pages\ViewRecord;
use App\Models\MonitorApiResult;
use Filament\Actions;

class ViewMonitorApis extends ViewRecord
{
    protected static string $resource = MonitorApisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            MonitorApisResource\Widgets\ResponseTimeChart::make(['record' => $this->record]),
        ];
    }
}