<?php

namespace App\Filament\Resources;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Filament\Resources\NotificationSettingResource\Pages;
use App\Models\NotificationSetting;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    public static function normalizeChannelData(array $data): array
    {
        if ($data['channel_type'] === NotificationChannelTypesEnum::MAIL->value) {
            $data['notification_channel_id'] = null;
        }

        if ($data['channel_type'] === NotificationChannelTypesEnum::WEBHOOK->value) {
            $data['address'] = null;
        }

        return $data;
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
                                    NotificationChannelTypesEnum::MAIL->value => ['required', 'email'],
                                    NotificationChannelTypesEnum::WEBHOOK->value => ['required', 'url'],
                                    default => [],
                                };
                            })
                            ->hidden(fn ($get) => $get('channel_type') !== NotificationChannelTypesEnum::MAIL->value),
                        Forms\Components\Select::make('notification_channel_id')
                            ->label('Notification Channel')
                            ->required()
                            ->options(fn () => auth()->user()->webhookChannels()->pluck('title', 'id'))
                            ->hidden(fn ($get) => $get('channel_type') !== NotificationChannelTypesEnum::WEBHOOK->value),
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
            ])
            ->filters([])
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
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No global notification rules yet')
            ->emptyStateDescription('Create a rule to be alerted by email or webhook when any of your monitors changes state. Rules added here apply automatically to every website with the matching monitor enabled.')
            ->emptyStateIcon('heroicon-o-bell-alert')
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Add notification rule')
                    ->icon('heroicon-o-plus'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->with('channel');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationSettings::route('/'),
            'create' => Pages\CreateNotificationSetting::route('/create'),
            'edit' => Pages\EditNotificationSetting::route('/{record}/edit'),
        ];
    }
}
