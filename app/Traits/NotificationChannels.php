<?php

namespace App\Traits;

use Filament\Actions\Action;
use Filament\Notifications\Notification;

trait NotificationChannels
{
    public function callTestWebhook($form): void
    {
        $validatedData = $form->getState();
        if ($form->validate()) {
            $callback = \App\Models\NotificationChannels::testWebhook($validatedData);

            if ($callback['code'] != 200) {
                $responseFail = true;
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
                $responseFail = false;
                $title = 'Webhook test successful!';
                $body = 'Your webhook has been triggered and processed correctly with current config';
            }

            if ($responseFail) {
                Notification::make()
                    ->danger()
                    ->title(__($title))
                    ->body(__($body))
                    ->send();
            } else {
                Notification::make()
                    ->success()
                    ->title(__($title))
                    ->body(__($body))
                    ->send();
            }
        }
    }

    public function testWebhookAction(): Action
    {
        return Action::make('Test Webhook')
            ->color('warning')
            ->button()
            ->outlined()
            ->action('testWebhook');
    }
}
