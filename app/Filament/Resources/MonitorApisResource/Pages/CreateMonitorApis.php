<?php

namespace App\Filament\Resources\MonitorApisResource\Pages;

use App\Filament\Resources\MonitorApisResource;
use App\Traits\MonitoringApis;
use Filament\Resources\Pages\CreateRecord;

class CreateMonitorApis extends CreateRecord
{
    use MonitoringApis;

    protected static string $resource = MonitorApisResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    public function doMonitoring(): void
    {
        $this->callDoMonitoring($this->form);
    }

    protected function getFormActions(): array
    {
        return array_merge([$this->doMonitorApiAction()], parent::getFormActions());
    }
}
