<?php

namespace App\Traits;

use Filament\Actions\Action;
use Filament\Notifications\Notification;

trait MonitoringApis
{
    public function callDoMonitoring($form): void
    {
        $validatedData = $form->getState();

        if ($form->validate()) {
            $callback = \App\Models\MonitorApis::testApi($validatedData);
            $failedAssertions = array_filter($callback['assertions'] ?? [], fn ($assertion) => ! ($assertion['passed'] ?? false));

            if (($callback['code'] ?? 0) === 0) {
                $responseFail = 'danger';
                $title = 'API request failed';
                $body = $callback['error'] ?? 'The API request could not be completed.';
            } else {
                // Initialize response type as success
                $responseFail = 'success';
                $title = 'API response received';
                $body = [];

                // Check if we have any assertions to validate
                if (! empty($callback['assertions'])) {
                    if (! empty($failedAssertions)) {
                        $responseFail = 'warning';
                        $title = 'Some API assertions failed';
                    }

                    // Build the response message
                    foreach ($callback['assertions'] as $assertion) {
                        $icon = $assertion['passed'] ? '✓' : '✗';
                        $path = $assertion['path'];
                        $type = $assertion['type'] ?? 'exists';
                        $message = $assertion['message'];

                        $body[] = "{$icon} Path: {$path}".($type !== 'exists' ? " [{$type}]" : '')." - {$message}";
                    }
                } else {
                    $body[] = 'No assertions configured for this API endpoint.';
                }

                // Join all messages with line breaks
                $body = implode("\n", $body);
            }

            Notification::make()
                ->{$responseFail}()
                ->title(__($title))
                ->body(__($body))
                ->send();
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
