<?php

namespace App\Filament\Resources\NotificationSettingResource\Pages;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Filament\Resources\NotificationSettingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNotificationSetting extends CreateRecord
{
    protected static string $resource = NotificationSettingResource::class;

    protected static ?string $title = 'Add New Global Notification';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();
        $data['scope'] = NotificationScopesEnum::GLOBAL->value;

        if ($data['channel_type'] === NotificationChannelTypesEnum::MAIL->value) {
            $data['notification_channel_id'] = null;
        }

        if ($data['channel_type'] === NotificationChannelTypesEnum::WEBHOOK->value) {
            $data['address'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->previousUrl ?? $this->getResource()::getUrl('index');
    }
}
