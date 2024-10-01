<?php

    namespace App\Filament\Resources\NotificationChannelsResource\Pages;

    use App\Enums\WebhookHttpMethod;
    use App\Filament\Resources\NotificationChannelsResource;
    use App\Traits\NotificationChannels;
    use Filament\Actions;
    use Filament\Resources\Pages\EditRecord;

    class EditNotificationChannels extends EditRecord
    {
        use NotificationChannels;

        protected static string $resource = NotificationChannelsResource::class;

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

        protected function mutateFormDataBeforeFill( array $data ): array
        {
            $data[ 'is_post_method' ] = $data[ 'method' ] === WebhookHttpMethod::POST->value;
            $data[ 'user_id' ]        = auth()->id();

            return $data;
        }

        public function testWebhook(): void
        {
            $this->callTestWebhook($this->form);
        }

        protected function getFormActions(): array
        {
            return array_merge([
                $this->testWebhookAction()
            ], parent::getFormActions());
        }

    }
