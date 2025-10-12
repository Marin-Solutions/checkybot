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

            if ($callback['code'] != 200) {
                $responseFail = 'danger';
                if ($callback['code'] == 60) {
                    $title = 'URL website, problem with certificate';
                    $body = $callback['body'];
                } elseif ($callback['body'] == 1) {
                    $title = 'URL Website Response error';
                    $body = 'The website response is not 200!';
                } else {
                    $title = 'URL website a unknown error. try other url';
                    $body = $callback['body'].' code errno:'.$callback['code'];
                }
            } else {
                // Initialize response type as success
                $responseFail = 'success';
                $title = 'API response received';
                $body = [];

                // Check if we have any assertions to validate
                if (! empty($callback['assertions'])) {
                    $failedAssertions = array_filter($callback['assertions'], fn ($assertion) => ! $assertion['passed']);

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

            //                if ( $responseFail ) {
            //                    Notification::make()
            //                        ->danger()
            //                        ->title(__($title))
            //                        ->body(__($body))
            //                        ->send()
            //                    ;
            //                } else {
            //                    Notification::make()
            //                        ->success()
            //                        ->title(__($title))
            //                        ->body(__($body))
            //                        ->send()
            //                    ;
            //                }
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
