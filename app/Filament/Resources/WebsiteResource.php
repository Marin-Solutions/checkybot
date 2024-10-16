<?php

    namespace App\Filament\Resources;

    use App\Crawlers\WebsiteOutboundLinkCrawler;
    use App\Enums\WebsiteServicesEnum;
    use App\Jobs\WebsiteCheckOutbondLinkJob;
    use App\Mail\EmailReminderSsl;
    use App\Models\NotificationSetting;
    use App\Models\User;
    use Carbon\Carbon;
    use Filament\Forms;
    use Filament\Notifications\Notification;
    use Filament\Tables;
    use App\Models\Website;
    use Filament\Forms\Form;
    use Filament\Tables\Table;
    use Filament\Resources\Resource;
    use Filament\Forms\Components\View;
    use Filament\Forms\Components\Split;
    use Filament\Forms\Components\Section;
    use Filament\Forms\Components\Fieldset;
    use Illuminate\Database\Eloquent\Builder;
    use App\Filament\Resources\WebsiteResource\Pages;
    use Illuminate\Database\Eloquent\SoftDeletingScope;
    use App\Filament\Resources\WebsiteResource\RelationManagers;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Facades\Mail;
    use Spatie\Crawler\Crawler;
    use Spatie\SslCertificate\SslCertificate;

    class WebsiteResource extends Resource
    {
        protected static ?string $model = Website::class;

        protected static ?string $navigationIcon = 'heroicon-o-globe-europe-africa';
        protected static ?string $navigationGroup = 'Operations';
        protected static ?int $navigationSort = 1;

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
                    Section::make(__(''))
                        ->schema([
                            Fieldset::make('Website Info')
                                ->translateLabel()
                                ->schema([
                                    Forms\Components\TextInput::make('name')
                                        ->translateLabel()
                                        ->required()
                                        ->columns(2)
                                        ->autofocus()
                                        ->placeholder(__('name'))
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('url')
                                        ->translateLabel()
                                        ->required()
                                        ->activeUrl()
                                        ->default('https://')
                                        ->validationMessages([
                                            'active_url' => 'The website Url not exists, try again'
                                        ])
                                        ->url()
                                        ->maxLength(255),
                                    Forms\Components\Textarea::make('description')
                                        ->translateLabel()
                                        ->columnSpanFull()
                                ]),
                            Fieldset::make('Monitoring info')
                                ->translateLabel()
                                ->schema([
                                    Split::make([
                                        fieldset::make('Uptime settings')
                                            ->schema([
                                                Forms\Components\Toggle::make('uptime_check')
                                                    ->translateLabel()
                                                    ->onColor('success')
                                                    ->inline(false)
                                                    ->columnSpan('1')
                                                    ->live()
                                                    ->required(),
                                                Forms\Components\Hidden::make('created_by'),
                                                Forms\Components\Select::make('uptime_interval')
                                                    ->options([
                                                        '1'    => '1 Minute',
                                                        '2'    => '2 Minutes',
                                                        '3'    => '3 Minutes',
                                                        '5'    => '5 Minutes',
                                                        '10'   => '10 Minutes',
                                                        '30'   => '30 Minutes',
                                                        '60'   => '1 Hour',
                                                        '360'  => '6 Hours',
                                                        '720'  => '12 Hours',
                                                        '1440' => '24 Hours',
                                                    ])
                                                    ->translateLabel()
                                                    ->required()
                                                    ->default(1),
                                            ])->columns(3),
                                        fieldset::make('SSL settings')
                                            ->schema([
                                                Forms\Components\Toggle::make('ssl_check')
                                                    ->translateLabel()
                                                    ->onColor('success')
                                                    ->inline(false)
                                                    ->columnSpan(1)
                                                    ->live()
                                                    ->default(1)
                                                    //->extraFieldWrapperAttributes(['style' => 'margin-left:4rem',])
                                                    ->required(),
                                            ])
                                    ])
                                ])->columns(1)
                        ])
                ])
            ;
        }

        public static function table( Table $table ): Table
        {
            return $table
                ->columns([
                    Tables\Columns\TextColumn::make('name')
                        ->translateLabel()
                        ->searchable(),
                    Tables\Columns\TextColumn::make('url')
                        ->translateLabel()
                        ->limit(50)
                        ->searchable(),
                    Tables\Columns\TextColumn::make('user.name')
                        ->label('Created By')
                        ->translateLabel()
                        ->numeric()
                        ->sortable(),
                    Tables\Columns\ToggleColumn::make('uptime_check')
                        ->translateLabel(),
                    Tables\Columns\SelectColumn::make('uptime_interval')
                        ->translateLabel()
                        ->options([
                            '1'    => '1 Minute',
                            '2'    => '2 Minutes',
                            '3'    => '3 Minutes',
                            '5'    => '5 Minutes',
                            '10'   => '10 Minutes',
                            '30'   => '30 Minutes',
                            '60'   => '1 Hour',
                            '360'  => '6 Hours',
                            '720'  => '12 Hours',
                            '1440' => '24 Hours',
                        ])
                        ->sortable(),
                    Tables\Columns\ToggleColumn::make('ssl_check')
                        ->label('SSL check')
                        ->translateLabel(),
                    Tables\Columns\TextColumn::make('ssl_expiry_date')
                        ->label('SSL expiry date')
                        ->translateLabel()
                        ->disabled(),
                    Tables\Columns\TextColumn::make('created_at')
                        ->translateLabel()
                        ->dateTime()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    Tables\Columns\TextColumn::make('updated_at')
                        ->translateLabel()
                        ->dateTime()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    Tables\Columns\TextColumn::make('deleted_at')
                        ->translateLabel()
                        ->dateTime()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->filters([
                    //Tables\Filters\TrashedFilter::make(),
                ])
                ->actions([
                    Tables\Actions\EditAction::make(),
                ])
                ->bulkActions([
                    Tables\Actions\BulkActionGroup::make([
                        Tables\Actions\DeleteBulkAction::make(),
                        Tables\Actions\ForceDeleteBulkAction::make(),
                        Tables\Actions\RestoreBulkAction::make(),
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
                'index'  => Pages\ListWebsites::route('/'),
                'create' => Pages\CreateWebsite::route('/create'),
                'edit'   => Pages\EditWebsite::route('/{record}/edit'),
            ];
        }

        public static function getEloquentQuery(): Builder
        {
            return parent::getEloquentQuery()
                ->withoutGlobalScopes([
                    SoftDeletingScope::class,
                ])
            ;
        }

        public static function getModelLabel(): string
        {
            return __('Website');
        }

        public static function getPluralModelLabel(): string
        {
            return __('Websites');
        }

    }
