<?php

namespace App\Traits;

use App\Models\MonitorApis;
use App\Support\ApiMonitorTestNotification;
use Filament\Actions\Action;

trait MonitoringApis
{
    public function callDoMonitoring($form, ?int $monitorId = null): void
    {
        $validatedData = $form->getState();
        $validatedData['id'] = $monitorId;
        $validatedData['request_body'] = $validatedData['request_body'] ?? null;
        $validatedData[MonitorApis::INTERACTIVE_RUN_KEY] = true;

        if ($form->validate()) {
            $result = MonitorApis::testApi($validatedData);
            $expectedStatus = isset($validatedData['expected_status']) ? (int) $validatedData['expected_status'] : null;

            ApiMonitorTestNotification::fromResult($result, $expectedStatus)->send();
        }
    }

    public function doMonitorApiAction(): Action
    {
        return Action::make('check_api')
            ->label('Check API')
            ->color('warning')
            ->button()
            ->outlined()
            ->action('doMonitoring');
    }
}
