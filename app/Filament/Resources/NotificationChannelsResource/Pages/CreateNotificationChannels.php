<?php

namespace App\Filament\Resources\NotificationChannelsResource\Pages;

use App\Enums\WebhookHttpMethod;
use App\Filament\Resources\NotificationChannelsResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNotificationChannels extends CreateRecord
{
    use \App\Traits\NotificationChannels;

    protected static string $resource = NotificationChannelsResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['request_body'] = $data['method'] === WebhookHttpMethod::GET->value ? '' : $data['request_body'];

        return $data;
    }

    public function testWebhook(): void
    {
        $this->callTestWebhook($this->form);
    }

    protected function getFormActions(): array
    {
        return array_merge([
            $this->testWebhookAction(),
        ], parent::getFormActions());
    }
}
