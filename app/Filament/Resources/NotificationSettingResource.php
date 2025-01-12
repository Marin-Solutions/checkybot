<?php

namespace App\Filament\Resources;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Filament\Resources\NotificationSettingResource\Pages;
use App\Models\NotificationSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationSettingResource extends Resource
{
    protected static ?string $model = NotificationSetting::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 5;
    protected static ?string $modelLabel = 'Global Notification';
    protected static ?string $pluralModelLabel = 'Global Notifications';

    public static function getNavigationBadge(): ?string
    {
        return number_format(static::getModel()::where('user_id', auth()->id())->count());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Fieldset::make('Notification Setting')
                    ->schema([
                        Forms\Components\Select::make('inspection')
                            ->label('Monitor')
                            ->options(WebsiteServicesEnum::toArray())
                            ->required(),
                    ])->columns(1),
                Forms\Components\Fieldset::make('Notification Channel')
                    ->schema([
                        Forms\Components\Select::make('channel_type')
                            ->options(NotificationChannelTypesEnum::toArray())
                            ->required()
                            ->reactive()
                            ->columnSpan(1),
                        Forms\Components\TextInput::make('address')
                            ->label("Email")
                            ->required()
                            ->columnSpan(1)
                            ->rules(function (callable $get) {
                                return match ($get('channel_type')) {
                                    NotificationChannelTypesEnum::MAIL->name => ['required', 'email'],
                                    NotificationChannelTypesEnum::WEBHOOK->name => ['required', 'url'],
                                };
                            })
                            ->hidden(fn($get) => $get("channel_type") !== NotificationChannelTypesEnum::MAIL->name),
                        Forms\Components\Select::make('notification_channel_id')
                            ->label('Notification Channel')
                            ->required()
                            ->options(fn() => auth()->user()->webhookChannels()->pluck('title', 'id'))
                            ->hidden(fn($get) => $get("channel_type") !== NotificationChannelTypesEnum::WEBHOOK->name)
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
                    ->visible(fn($record) => $record->channel_type === NotificationChannelTypesEnum::WEBHOOK->name),
                Tables\Columns\TextColumn::make('address')
                    ->visible(fn($record) => $record->channel_type === NotificationChannelTypesEnum::MAIL->name),
                Tables\Columns\ToggleColumn::make('flag_active')
                    ->label('Active'),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
}
