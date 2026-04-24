<?php

namespace App\Filament\Resources;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Filament\Resources\NotificationSettingResource\Pages;
use App\Mail\HealthStatusAlert;
use App\Models\NotificationSetting;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class NotificationSettingResource extends Resource
{
    protected static ?string $model = NotificationSetting::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog';

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Global Notification';

    protected static ?string $pluralModelLabel = 'Global Notifications';

    public static function getNavigationBadge(): ?string
    {
        return number_format(static::getModel()::where('user_id', auth()->id())->count());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Fieldset::make('Notification Setting')
                    ->schema([
                        Forms\Components\Select::make('inspection')
                            ->label('Monitor')
                            ->options(WebsiteServicesEnum::toArray())
                            ->required(),
                    ])->columns(1),
                \Filament\Schemas\Components\Fieldset::make('Notification Channel')
                    ->schema([
                        Forms\Components\Select::make('channel_type')
                            ->options(NotificationChannelTypesEnum::toArray())
                            ->required()
                            ->reactive()
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('address')
                            ->label('Email')
                            ->required()
                            ->columnSpan(1)
                            ->rules(function (callable $get) {
                                return match ($get('channel_type')) {
                                    NotificationChannelTypesEnum::MAIL->name => ['required', 'email'],
                                    NotificationChannelTypesEnum::WEBHOOK->name => ['required', 'url'],
                                };
                            })
                            ->hidden(fn ($get) => $get('channel_type') !== NotificationChannelTypesEnum::MAIL->name),
                        Forms\Components\Select::make('notification_channel_id')
                            ->label('Notification Channel')
                            ->required()
                            ->options(fn () => auth()->user()->webhookChannels()->pluck('title', 'id'))
                            ->hidden(fn ($get) => $get('channel_type') !== NotificationChannelTypesEnum::WEBHOOK->name),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('inspection_value')
                    ->label('Monitor'),
                Tables\Columns\TextColumn::make('channel_type_value')
                    ->label('Channel Type'),
                Tables\Columns\TextColumn::make('channel.title')
                    ->label('Channel Name')
                    ->visible(fn ($record) => $record && $record->channel_type === NotificationChannelTypesEnum::WEBHOOK->name),
                Tables\Columns\TextColumn::make('address')
                    ->visible(fn ($record) => $record && $record->channel_type === NotificationChannelTypesEnum::MAIL->name),
                Tables\Columns\ToggleColumn::make('flag_active')
                    ->label('Active'),
            ])
            ->filters([])
            ->actions([
                \Filament\Actions\Action::make('send_test')
                    ->label('Send Test')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (NotificationSetting $record): string => $record->channel_type === NotificationChannelTypesEnum::MAIL
                        ? 'Send a test email alert'
                        : 'Send a test webhook alert')
                    ->modalDescription(function (NotificationSetting $record): string {
                        if ($record->channel_type === NotificationChannelTypesEnum::MAIL) {
                            return "A sample Health Status Alert email will be sent to {$record->address} so you can confirm delivery, formatting, and deliverability.";
                        }

                        $channelTitle = $record->channel?->title ?? 'the linked channel';

                        return "This will trigger a real request through {$channelTitle} with a sample payload so you can confirm the integration works end-to-end.";
                    })
                    ->modalSubmitActionLabel('Send test')
                    ->action(function (NotificationSetting $record): void {
                        static::sendTestNotification($record);
                    }),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationSettings::route('/'),
            'create' => Pages\CreateNotificationSetting::route('/create'),
            'edit' => Pages\EditNotificationSetting::route('/{record}/edit'),
        ];
    }

    /**
     * Send a real test notification for this setting (email or webhook) and
     * surface the outcome to the user so they can validate their configuration
     * before waiting for a real incident.
     *
     * Uses `match` over the enum so that adding a new NotificationChannelTypesEnum
     * case without handling it here fails loudly (UnhandledMatchError) instead of
     * silently falling through to a no-op notification.
     */
    public static function sendTestNotification(NotificationSetting $record): void
    {
        match ($record->channel_type) {
            NotificationChannelTypesEnum::MAIL => static::sendTestEmail($record),
            NotificationChannelTypesEnum::WEBHOOK => static::sendTestWebhook($record),
        };
    }

    protected static function sendTestEmail(NotificationSetting $record): void
    {
        if (empty($record->address)) {
            Notification::make()
                ->danger()
                ->title('Missing email address')
                ->body('Add an email address to this notification setting before sending a test.')
                ->send();

            return;
        }

        try {
            Mail::to($record->address)->send(new HealthStatusAlert(
                name: 'Checkybot Test Alert',
                event: 'test',
                eventLabel: 'Test Notification',
                status: 'test',
                summary: 'This is a test email triggered from Global Notifications to verify delivery. No real incident is happening.',
                url: config('app.url'),
            ));

            Notification::make()
                ->success()
                ->title('Test email sent')
                ->body(new HtmlString(
                    '<strong>To:</strong> '.e($record->address).'<br>'
                    .'Check the inbox (and spam folder). If it never arrives, check Horizon / queue workers and mail driver configuration.'
                ))
                ->persistent()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Test email failed to send')
                ->body(new HtmlString(
                    '<strong>Error:</strong> '.e(Str::limit($exception->getMessage(), 600))
                ))
                ->persistent()
                ->send();
        }
    }

    protected static function sendTestWebhook(NotificationSetting $record): void
    {
        $channel = $record->channel;

        if (! $channel) {
            Notification::make()
                ->danger()
                ->title('Missing webhook channel')
                ->body('This setting is not linked to a webhook channel. Edit the setting and pick a channel first.')
                ->send();

            return;
        }

        NotificationChannelsResource::sendTestWebhook($channel);
    }
}
