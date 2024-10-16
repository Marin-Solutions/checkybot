<?php

    namespace App\Filament\Resources\NotificationSettingResource\Pages;

    use App\Enums\NotificationChannelTypesEnum;
    use App\Filament\Resources\NotificationSettingResource;
    use Filament\Actions;
    use Filament\Resources\Pages\CreateRecord;

    class CreateNotificationSetting extends CreateRecord
    {
        protected static string $resource = NotificationSettingResource::class;

        protected static ?string $title = "Add New Notification Channel";

        protected static ?string $breadcrumb = 'Add new';

        protected function getRedirectUrl(): string
        {
            return $this->previousUrl ?? $this->getResource()::getUrl('index');
        }

        protected function mutateFormDataBeforeCreate( array $data ): array
        {
            $data[ 'user_id' ] = auth()->id();

            if ( $data[ 'channel_type' ] === NotificationChannelTypesEnum::MAIL->name ) {
                $data[ 'notification_channel_id' ] = null;
            }

            if ( $data[ 'channel_type' ] === NotificationChannelTypesEnum::WEBHOOK->name ) {
                $data[ 'address' ] = null;
            }

            return $data;
        }
    }
