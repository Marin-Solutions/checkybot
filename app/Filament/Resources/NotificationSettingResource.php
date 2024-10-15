<?php

    namespace App\Filament\Resources;

    use App\Enums\NotificationChannelTypesEnum;
    use App\Enums\NotificationScopesEnum;
    use App\Enums\WebsiteServicesEnum;
    use App\Filament\Resources\NotificationSettingResource\Pages;
    use App\Filament\Resources\NotificationSettingResource\RelationManagers;
    use App\Models\NotificationSetting;
    use Filament\Forms;
    use Filament\Forms\Form;
    use Filament\Resources\Resource;
    use Filament\Tables;
    use Filament\Tables\Table;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\SoftDeletingScope;
    use Illuminate\Support\HtmlString;

    class NotificationSettingResource extends Resource
    {
        protected static ?string $model = NotificationSetting::class;

        protected static ?string $navigationIcon = 'heroicon-o-cog';
        protected static ?string $navigationGroup = 'Operations';
        protected static ?int $navigationSort = 5;

        /**
         * Get the navigation badge for the resource.
         */
        public static function getNavigationBadge(): ?string
        {
            return number_format(static::getModel()::count());
        }

        public static function form( Form $form ): Form
        {
            return $form
                ->schema([
                    Forms\Components\Fieldset::make('Notification Setting')
                        ->schema([
                            Forms\Components\Select::make('scope')
                                ->options(NotificationScopesEnum::toArray())
                                ->required()
                                ->live()
                                ->columnSpan(1),
                            Forms\Components\Select::make('website_id')
                                ->relationship(name: 'website', titleAttribute: 'name')
                                ->reactive()
                                ->required(fn( $get ) => $get("scope") === NotificationScopesEnum::WEBSITE->name)
                                ->disabled(fn( $get ) => $get("scope") !== NotificationScopesEnum::WEBSITE->name)
                                ->columnSpan(1),
                            Forms\Components\Select::make('inspection')
                                ->options(WebsiteServicesEnum::toArray())
                                ->required()
                                ->columnSpan(1),
                        ])->columns(3),
                    Forms\Components\Fieldset::make('Notification Channel')
                        ->schema([
                            Forms\Components\Select::make('channel_type')
                                ->options(NotificationChannelTypesEnum::toArray())
                                ->required()
                                ->columnSpan(1),
                            Forms\Components\TextInput::make('address')
                                ->label("Address (email/phone number/url)")
                                ->required()
                                ->columnSpan(2)
                                ->rules(function ( callable $get ) {
                                    return match ( $get('channel_type') ) {
                                        NotificationChannelTypesEnum::MAIL->name => [ 'required', 'email' ],
                                        NotificationChannelTypesEnum::SMS->name => [ 'required', 'regex:/^\+?([0-9]{1,4})?([0-9]{10,15})$/' ],
                                        NotificationChannelTypesEnum::WEBHOOK->name => [ 'required', 'url' ],
                                    };
                                })
                        ])->columns(2),
                ])
            ;
        }

        public static function table( Table $table ): Table
        {
            return $table
                ->columns([
                    Tables\Columns\TextColumn::make('scope_value'),
                    Tables\Columns\TextColumn::make('user.name')->toggleable(isToggledHiddenByDefault: true),
                    Tables\Columns\TextColumn::make('website.name')->toggleable(isToggledHiddenByDefault: true),
                    Tables\Columns\TextColumn::make('inspection_value'),
                    Tables\Columns\TextColumn::make('channel_type_value'),
                    Tables\Columns\TextColumn::make('address'),
                    Tables\Columns\ToggleColumn::make('flag_active')
                ])
                ->filters([
                    //
                ])
                ->actions([
                    Tables\Actions\EditAction::make(),
                ])
                ->bulkActions([
                    Tables\Actions\BulkActionGroup::make([
                        Tables\Actions\DeleteBulkAction::make(),
                    ]),
                ])
            ;
        }

        public static function getRelations(): array
        {
            return [
                //
            ];
        }

        public static function getPages(): array
        {
            return [
                'index'  => Pages\ListNotificationSettings::route('/'),
                'create' => Pages\CreateNotificationSetting::route('/create'),
                'edit'   => Pages\EditNotificationSetting::route('/{record}/edit'),
            ];
        }
    }
