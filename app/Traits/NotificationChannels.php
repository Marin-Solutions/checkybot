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
            $validatedData['request_body'] = is_array($validatedData['request_body'] ?? null)
                ? $validatedData['request_body']
                : [];

            $callback = \App\Models\NotificationChannels::testWebhook($validatedData);
            $code = (int) ($callback['code'] ?? 0);
            $successful = $code >= 200 && $code < 300;

            if (isset($this->record) && $this->record instanceof \App\Models\NotificationChannels) {
                $this->record->recordDeliveryAttempt(
                    kind: 'test',
                    succeeded: $successful,
                    responseCode: $code ?: null,
                    summary: \App\Models\NotificationChannels::summarizeDeliveryResponse($callback),
                );
            }

            if (! $successful) {
                $responseFail = true;
                $bodyDetail = is_string($callback['body'] ?? null)
                    ? $callback['body']
                    : (json_encode($callback['body'] ?? null) ?: '');
                // testWebhook() reuses the `code` key for two namespaces:
                // real HTTP status codes (100–599) on a completed request,
                // and curl errnos (e.g. 60 for SSL) when a RequestException
                // is caught. Label them differently so the operator can tell
                // a 502 apart from a TLS handshake failure.
                if ($code == 60) {
                    $title = 'URL website, problem with certificate';
                    $body = $bodyDetail;
                } elseif ($callback['body'] == 1) {
                    $title = 'URL Website Response error';
                    $body = 'The website response is not 2xx!';
                } elseif ($code >= 100 && $code < 600) {
                    $title = 'Webhook returned an error status';
                    $body = $bodyDetail.' (HTTP '.$code.')';
                } else {
                    $title = 'URL website a unknown error. try other url';
                    $body = $bodyDetail.' (curl errno '.$code.')';
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
