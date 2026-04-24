<?php

namespace App\Filament\Resources\WebsiteResource\RelationManagers;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Models\NotificationSetting;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class NotificationSettingsRelationManager extends RelationManager
{
    protected static string $relationship = 'notificationSettings';

    protected static ?string $title = 'Website Notifications';

    protected static ?string $recordTitleAttribute = 'inspection';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\Select::make('inspection')
                    ->label('Monitor')
                    ->options([
                        WebsiteServicesEnum::WEBSITE_CHECK->value => WebsiteServicesEnum::WEBSITE_CHECK->label(),
                        WebsiteServicesEnum::ALL_CHECK->value => WebsiteServicesEnum::ALL_CHECK->label(),
                    ])
                    ->default(WebsiteServicesEnum::WEBSITE_CHECK->value)
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
                    ->options(fn (): array => $this->getOwnerRecord()->user->webhookChannels()->pluck('title', 'id')->all())
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
            ->recordTitleAttribute('inspection')
            ->columns([
                Tables\Columns\TextColumn::make('inspection_value')
                    ->label('Monitor'),
                Tables\Columns\TextColumn::make('channel_type_value')
                    ->label('Channel Type'),
                Tables\Columns\TextColumn::make('channel.title')
                    ->label('Channel')
                    ->placeholder('Email delivery')
                    ->visible(fn (?NotificationSetting $record): bool => $record?->channel_type === NotificationChannelTypesEnum::WEBHOOK),
                Tables\Columns\TextColumn::make('address')
                    ->label('Destination')
                    ->visible(fn (?NotificationSetting $record): bool => $record?->channel_type === NotificationChannelTypesEnum::MAIL),
                Tables\Columns\ToggleColumn::make('flag_active')
                    ->label('Active'),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Add Website Alert')
                    ->mutateDataUsing(fn (array $data): array => $this->mutateNotificationData($data)),
            ])
            ->actions([
                \Filament\Actions\EditAction::make()
                    ->mutateDataUsing(fn (array $data): array => $this->mutateNotificationData($data)),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No website-specific alerts configured')
            ->emptyStateDescription('Route alerts for this website directly from the check detail page.');
    }

    protected function mutateNotificationData(array $data): array
    {
        $data['user_id'] = $this->getOwnerRecord()->created_by;
        $data['website_id'] = $this->getOwnerRecord()->getKey();
        $data['scope'] = NotificationScopesEnum::WEBSITE->value;

        if ($data['channel_type'] === NotificationChannelTypesEnum::MAIL->value) {
            $data['notification_channel_id'] = null;
        }

        if ($data['channel_type'] === NotificationChannelTypesEnum::WEBHOOK->value) {
            $data['address'] = null;
        }

        return $data;
    }
}
