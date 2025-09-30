<?php

namespace App\Filament\Resources;

use App\Crawlers\WebsiteOutboundLinkCrawler;
use App\Jobs\WebsiteCheckOutboundLinkJob;
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
use Spatie\Crawler\Crawler;
use App\Tables\Columns\SparklineColumn;

class WebsiteResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 1;

    /**
     * Get the navigation badge for the resource.
     */
    public static function getNavigationBadge(): ?string
    {
        return number_format(static::getModel()::where('created_by', auth()->id())->count());
    }

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function form(Form $form): Form
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
                                Forms\Components\Grid::make()
                                    ->columns([
                                        'md' => 2,
                                        'xl' => 3
                                    ])
                                    ->schema([
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
                                                        1 => 'Every minute',
                                                        5 => 'Every 5 minutes',
                                                        10 => 'Every 10 minutes',
                                                        15 => 'Every 15 minutes',
                                                        30 => 'Every 30 minutes',
                                                        60 => 'Every hour',
                                                        360 => 'Every 6 hours',
                                                        720 => 'Every 12 hours',
                                                        1440 => 'Every 24 hours',
                                                    ])
                                                    ->translateLabel()
                                                    ->required(),
                                            ])->columns(2)->columnSpan(1),
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
                                            ])->columnSpan(1),
                                        fieldset::make('Outbound settings')
                                            ->schema([
                                                Forms\Components\Toggle::make('outbound_check')
                                                    ->translateLabel()
                                                    ->onColor('success')
                                                    ->inline(false)
                                                    ->live()
                                                    //->extraFieldWrapperAttributes(['style' => 'margin-left:4rem',])
                                                    ->required(),
                                            ])->columnSpan(1),
                                    ]),
                            ])->columns(1)
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(15)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->translateLabel()
                    ->searchable(),
                Tables\Columns\TextColumn::make('url')
                    ->translateLabel()
                    ->limit(50)
                    ->searchable(),
                SparklineColumn::make('response_times')
                    ->label('Response Time (24h)')
                    ->translateLabel()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->state(function (Website $record): array {
                        return $record->logHistory()
                            ->where('created_at', '>=', now()->subHours(24))
                            ->orderBy('created_at')
                            ->get()
                            ->map(fn($log) => [
                                'date' => $log->created_at->format('M j, H:i'),
                                'value' => $log->speed
                            ])
                            ->toArray();
                    }),
                Tables\Columns\TextColumn::make('average_response_time')
                    ->label('Avg Response (24h)')
                    ->translateLabel()
                    ->state(function (Website $record): string {
                        $avg = $record->average_response_time;

                        return $avg ? round($avg) . 'ms' : 'N/A';
                    })
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Created By')
                    ->translateLabel()
                    ->numeric()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('uptime_check')
                    ->translateLabel()
                    ->sortable(),
                Tables\Columns\SelectColumn::make('uptime_interval')
                    ->translateLabel()
                    ->options([
                        1 => 'Every minute',
                        5 => 'Every 5 minutes',
                        10 => 'Every 10 minutes',
                        15 => 'Every 15 minutes',
                        30 => 'Every 30 minutes',
                        60 => 'Every hour',
                        360 => 'Every 6 hours',
                        720 => 'Every 12 hours',
                        1440 => 'Every 24 hours',
                    ])
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('ssl_check')
                    ->label('SSL check')
                    ->translateLabel()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ssl_expiry_date')
                    ->label('SSL expiry date')
                    ->translateLabel()
                    ->disabled()
                    ->sortable(),
                Tables\Columns\ToggleColumn::make('outbound_check')
                    ->label('Outbound check')
                    ->translateLabel()
                    ->sortable(),
                Tables\Columns\TextColumn::make('global_notifications_count')
                    ->label('Global Notifications Channels')
                    ->state(function (Website $record): string {
                        return $record->globalNotifications->count() . '  🌍';
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('individual_notifications_count')
                    ->label('Individual Notifications Channels')
                    ->state(function (Website $record): string {
                        return $record->individualNotifications->count() . '  📌';
                    })
                    ->sortable(),
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
                Tables\Columns\TextColumn::make('last_outbound_checked_at')
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
            ->withAvg('logHistoryLast24h as average_response_time', 'speed')
            ->with([
                'user:id,name',
                'globalNotifications:id,user_id,website_id,inspection',
                'individualNotifications:id,website_id,inspection',
            ])
            ->where('created_by', Auth::id())
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
