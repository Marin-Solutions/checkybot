<?php

    namespace App\Filament\Resources\MonitorApisResource\Pages;

    use App\Filament\Resources\MonitorApisResource;
    use App\Traits\MonitoringApis;
    use Filament\Actions;
    use Filament\Resources\Pages\EditRecord;

    class EditMonitorApis extends EditRecord
    {
        use MonitoringApis;

        protected static string $resource = MonitorApisResource::class;

        protected function getRedirectUrl(): string
        {
            return $this->previousUrl ?? $this->getResource()::getUrl('index');
        }

        protected function getHeaderActions(): array
        {
            return [
                Actions\DeleteAction::make(),
            ];
        }

        public function doMonitoring(): void
        {
            $this->callDoMonitoring($this->form);
        }

        protected function getFormActions(): array
        {
            return array_merge([
                $this->doMonitorApiAction()
            ], parent::getFormActions());
        }
    }
