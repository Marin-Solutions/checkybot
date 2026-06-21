<?php

namespace App\Filament\Resources\MonitorApisResource\RelationManagers;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Filament\Support\NotificationSettingFilters;
use App\Models\NotificationSetting;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class NotificationSettingsRelationManager extends RelationManager
{
    private const API_INSPECTION_OPTIONS = [
        WebsiteServicesEnum::API_MONITOR->value => 'API Monitor',
        WebsiteServicesEnum::ALL_CHECK->value => 'All Check',
    ];

    protected static string $relationship = 'notificationSettings';

    protected static ?string $title = 'API Notifications';

    protected static ?string $recordTitleAttribute = 'inspection_value';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('inspection')
                    ->label('Monitor')
                    ->options(self::API_INSPECTION_OPTIONS)
                    ->default(WebsiteServicesEnum::API_MONITOR->value)
                    ->required(),
                Forms\Components\Select::make('channel_type')
                    ->label('Channel Type')
                    ->options(NotificationChannelTypesEnum::toArray())
                    ->required()
                    ->live(),
                Forms\Components\TextInput::make('address')
                    ->label('Email')
                    ->email()
                    ->required(fn (Get $get): bool => $get('channel_type') === NotificationChannelTypesEnum::MAIL->value)
                    ->hidden(fn (Get $get): bool => $get('channel_type') !== NotificationChannelTypesEnum::MAIL->value),
                Forms\Components\Select::make('notification_channel_id')
                    ->label('Notification Channel')
                    ->options(fn (): array => $this->getOwnerRecord()->user?->webhookChannels()?->pluck('title', 'id')->all() ?? [])
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get): void {
                            if ($get('channel_type') !== NotificationChannelTypesEnum::WEBHOOK->value || blank($value)) {
                                return;
                            }

                            $owner = $this->getOwnerRecord()->user;
                            $owned = $owner !== null
                                && $owner->webhookChannels()
                                    ->whereKey($value)
                                    ->exists();

                            if (! $owned) {
                                $fail('Select one of this API monitor owner\'s webhook channels.');
                            }
                        },
                    ])
                    ->required(fn (Get $get): bool => $get('channel_type') === NotificationChannelTypesEnum::WEBHOOK->value)
                    ->hidden(fn (Get $get): bool => $get('channel_type') !== NotificationChannelTypesEnum::WEBHOOK->value),
                Forms\Components\Toggle::make('flag_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('inspection_value')
            ->modifyQueryUsing(fn ($query) => $query->with('channel'))
            ->columns([
                Tables\Columns\TextColumn::make('inspection_value')
                    ->label('Monitor'),
                Tables\Columns\TextColumn::make('channel_type_value')
                    ->label('Channel Type'),
                Tables\Columns\TextColumn::make('channel.title')
                    ->label('Channel')
                    ->placeholder('Email delivery')
                    ->state(fn (NotificationSetting $record): ?string => match (true) {
                        $record->channel_type !== NotificationChannelTypesEnum::WEBHOOK => null,
                        $record->channel !== null => $record->channel->title,
                        default => '(channel removed)',
                    }),
                Tables\Columns\TextColumn::make('address')
                    ->label('Destination')
                    ->placeholder('Webhook channel')
                    ->state(fn (NotificationSetting $record): ?string => $record->channel_type === NotificationChannelTypesEnum::MAIL
                        ? $record->address
                        : null),
                Tables\Columns\ToggleColumn::make('flag_active')
                    ->label('Active'),
                Tables\Columns\TextColumn::make('last_delivery_succeeded')
                    ->label('Last Delivery')
                    ->badge()
                    ->placeholder('No delivery evidence')
                    ->state(fn (NotificationSetting $record): ?string => $record->last_delivery_attempted_at
                        ? (($record->last_delivery_succeeded ? 'Success' : 'Failed').' '.($record->last_delivery_kind ?? 'send'))
                        : null)
                    ->color(fn (NotificationSetting $record): string => match ($record->last_delivery_succeeded) {
                        true => 'success',
                        false => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('last_delivery_response_code')
                    ->label('Response')
                    ->placeholder('No response code')
                    ->state(fn (NotificationSetting $record): ?string => $record->last_delivery_response_code
                        ? (string) $record->last_delivery_response_code
                        : null),
                Tables\Columns\TextColumn::make('last_delivery_summary')
                    ->label('Delivery Evidence')
                    ->placeholder('No test or send recorded yet')
                    ->wrap()
                    ->limit(100),
                Tables\Columns\TextColumn::make('last_delivery_attempted_at')
                    ->label('Attempted')
                    ->placeholder('Never')
                    ->dateTime(),
            ])
            ->filters(NotificationSettingFilters::all())
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Add API Alert')
                    ->mutateDataUsing(fn (array $data): array => $this->mutateNotificationData($data)),
            ])
            ->actions([
                \Filament\Actions\Action::make('sendTest')
                    ->label('Send Test')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Send a test notification?')
                    ->modalDescription(function (NotificationSetting $record): string {
                        if ($record->channel_type === NotificationChannelTypesEnum::MAIL) {
                            return 'A sample alert email will be delivered to '.($record->address ?? 'the configured address').'.';
                        }

                        $title = $record->channel?->title;

                        return 'A sample payload will be sent to the linked webhook channel'.($title ? ' "'.$title.'"' : '').'.';
                    })
                    ->modalSubmitActionLabel('Send test')
                    ->action(function (NotificationSetting $record): void {
                        $result = $record->sendTestNotification();

                        $notification = Notification::make()
                            ->title(__($result['title']))
                            ->body(__($result['body']));

                        ($result['ok'] ? $notification->success() : $notification->danger())->send();
                    }),
                \Filament\Actions\EditAction::make()
                    ->mutateDataUsing(fn (array $data): array => $this->mutateNotificationData($data)),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No API-specific alerts configured')
            ->emptyStateDescription('Add an alert below to route incidents from this endpoint to the right team channel.')
            ->emptyStateIcon('heroicon-o-bell-alert');
    }

    protected function mutateNotificationData(array $data): array
    {
        $data['user_id'] = $this->getOwnerRecord()->created_by;
        $data['monitor_api_id'] = $this->getOwnerRecord()->getKey();
        $data['website_id'] = null;
        $data['scope'] = NotificationScopesEnum::API_MONITOR->value;

        if ($data['channel_type'] === NotificationChannelTypesEnum::MAIL->value) {
            $data['notification_channel_id'] = null;
        }

        if ($data['channel_type'] === NotificationChannelTypesEnum::WEBHOOK->value) {
            $data['address'] = null;
        }

        return $data;
    }
}
