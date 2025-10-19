<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebsiteResource\Pages;
use App\Models\Website;
use App\Services\SeoHealthCheckService;
use App\Tables\Columns\SparklineColumn;
use Filament\Forms;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WebsiteResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

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

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Schemas\Components\Section::make(__(''))
                    ->columnSpanFull()
                    ->schema([
                        \Filament\Schemas\Components\Fieldset::make('Website Info')
                            ->translateLabel()
                            ->schema([
                                \Filament\Forms\Components\TextInput::make('name')
                                    ->translateLabel()
                                    ->required()
                                    ->columns(2)
                                    ->autofocus()
                                    ->placeholder(__('name'))
                                    ->maxLength(255),
                                \Filament\Forms\Components\TextInput::make('url')
                                    ->translateLabel()
                                    ->required()
                                    ->activeUrl()
                                    ->default('https://')
                                    ->validationMessages([
                                        'active_url' => 'The website Url not exists, try again',
                                    ])
                                    ->url()
                                    ->maxLength(255),
                                \Filament\Forms\Components\Textarea::make('description')
                                    ->translateLabel()
                                    ->columnSpanFull(),
                            ]),
                        \Filament\Schemas\Components\Fieldset::make('Monitoring info')
                            ->translateLabel()
                            ->schema([
                                \Filament\Schemas\Components\Grid::make()
                                    ->columns([
                                        'md' => 2,
                                        'xl' => 3,
                                    ])
                                    ->schema([
                                        fieldset::make('Uptime settings')
                                            ->schema([
                                                \Filament\Forms\Components\Toggle::make('uptime_check')
                                                    ->translateLabel()
                                                    ->onColor('success')
                                                    ->inline(false)
                                                    ->columnSpan('1')
                                                    ->live()
                                                    ->required(),
                                                \Filament\Forms\Components\Hidden::make('created_by'),
                                                \Filament\Forms\Components\Select::make('uptime_interval')
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
                                                \Filament\Forms\Components\Toggle::make('ssl_check')
                                                    ->translateLabel()
                                                    ->onColor('success')
                                                    ->inline(false)
                                                    ->columnSpan(1)
                                                    ->live()
                                                    ->default(1)
                                                    // ->extraFieldWrapperAttributes(['style' => 'margin-left:4rem',])
                                                    ->required(),
                                            ])->columnSpan(1),
                                        fieldset::make('Outbound settings')
                                            ->schema([
                                                \Filament\Forms\Components\Toggle::make('outbound_check')
                                                    ->translateLabel()
                                                    ->onColor('success')
                                                    ->inline(false)
                                                    ->live()
                                                    // ->extraFieldWrapperAttributes(['style' => 'margin-left:4rem',])
                                                    ->required(),
                                            ])->columnSpan(1),
                                        fieldset::make('SEO Health Check Schedule')
                                            ->schema([
                                                \Filament\Forms\Components\Toggle::make('seo_schedule_enabled')
                                                    ->label('Enable Scheduled SEO Checks')
                                                    ->onColor('success')
                                                    ->inline(false)
                                                    ->live()
                                                    ->afterStateUpdated(function ($state, \Filament\Schemas\Components\Utilities\Set $set) {
                                                        if (! $state) {
                                                            $set('seo_schedule_frequency', null);
                                                            $set('seo_schedule_time', null);
                                                            $set('seo_schedule_day', null);
                                                        }
                                                    })
                                                    ->dehydrated(false),
                                                \Filament\Forms\Components\Select::make('seo_schedule_frequency')
                                                    ->label('Frequency')
                                                    ->options([
                                                        'daily' => 'Daily',
                                                        'weekly' => 'Weekly',
                                                    ])
                                                    ->live()
                                                    ->visible(fn(\Filament\Schemas\Components\Utilities\Get $get) => $get('seo_schedule_enabled'))
                                                    ->required(fn(\Filament\Schemas\Components\Utilities\Get $get) => $get('seo_schedule_enabled'))
                                                    ->dehydrated(false),
                                                \Filament\Forms\Components\TimePicker::make('seo_schedule_time')
                                                    ->label('Run Time')
                                                    ->default('02:00')
                                                    ->seconds(false)
                                                    ->visible(fn(\Filament\Schemas\Components\Utilities\Get $get) => $get('seo_schedule_enabled'))
                                                    ->required(fn(\Filament\Schemas\Components\Utilities\Get $get) => $get('seo_schedule_enabled'))
                                                    ->helperText('Time when the check will run (server timezone)')
                                                    ->dehydrated(false),
                                                \Filament\Forms\Components\Select::make('seo_schedule_day')
                                                    ->label('Day of Week')
                                                    ->options([
                                                        'Monday' => 'Monday',
                                                        'Tuesday' => 'Tuesday',
                                                        'Wednesday' => 'Wednesday',
                                                        'Thursday' => 'Thursday',
                                                        'Friday' => 'Friday',
                                                        'Saturday' => 'Saturday',
                                                        'Sunday' => 'Sunday',
                                                    ])
                                                    ->default('Monday')
                                                    ->visible(fn(\Filament\Schemas\Components\Utilities\Get $get) => $get('seo_schedule_enabled') && $get('seo_schedule_frequency') === 'weekly')
                                                    ->required(fn(\Filament\Schemas\Components\Utilities\Get $get) => $get('seo_schedule_enabled') && $get('seo_schedule_frequency') === 'weekly')
                                                    ->dehydrated(false),
                                            ])->columnSpan(1),
                                    ]),
                            ])->columns(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(15)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->translateLabel()
                    ->searchable()
                    ->formatStateUsing(function ($state, Website $record) {
                        $latestCheck = $record->latestSeoCheck;
                        if ($latestCheck && in_array($latestCheck->status, ['running', 'pending'])) {
                            return $state . ' 🔄';
                        }

                        return $state;
                    }),
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
                                'value' => $log->speed,
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
                Tables\Columns\TextColumn::make('latest_seo_check.status')
                    ->label('SEO Crawl Status')
                    ->formatStateUsing(function ($state, $record) {
                        $latestCheck = $record->latestSeoCheck;
                        if (! $latestCheck) {
                            return 'Not crawled';
                        }

                        $status = $latestCheck->status;
                        $progress = $latestCheck->getProgressPercentage();

                        if ($status === 'running') {
                            return "Crawling ({$progress}%)";
                        } elseif ($status === 'failed') {
                            return 'Failed';
                        } elseif ($status === 'completed') {
                            return 'Completed';
                        }

                        return ucfirst($status);
                    })
                    ->badge()
                    ->color(function ($state) {
                        if (str_contains($state, 'Not crawled') || str_contains($state, 'Failed')) {
                            return 'danger';
                        }
                        if (str_contains($state, 'Crawling') || str_contains($state, 'Pending')) {
                            return 'warning';
                        }
                        if (str_contains($state, 'Completed')) {
                            return 'success';
                        }

                        return 'gray';
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('latest_seo_check.started_at')
                    ->label('Last SEO Crawl')
                    ->formatStateUsing(function ($state, $record) {
                        $latestCheck = $record->latestSeoCheck;
                        if (! $latestCheck || ! $latestCheck->started_at) {
                            return 'Never';
                        }

                        return $latestCheck->started_at->diffForHumans();
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('latest_seo_check.total_urls_crawled')
                    ->label('URLs Crawled')
                    ->formatStateUsing(function ($state, $record) {
                        $latestCheck = $record->latestSeoCheck;
                        if (! $latestCheck) {
                            return '-';
                        }

                        $crawled = $latestCheck->total_urls_crawled;
                        $total = $latestCheck->total_crawlable_urls;
                        $status = $latestCheck->status;

                        if ($status === 'running') {
                            return "{$crawled}/{$total} (running...)";
                        }

                        return $total > 0 ? "{$crawled}/{$total}" : ($crawled ?: '-');
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->translateLabel()
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('view_seo_progress')
                    ->label('View Progress')
                    ->icon('heroicon-o-chart-bar')
                    ->color('warning')
                    ->url(function (Website $record) {
                        $latestCheck = $record->latestSeoCheck;
                        if ($latestCheck) {
                            return "/admin/seo-checks/{$latestCheck->id}";
                        }

                        return null;
                    })
                    ->openUrlInNewTab()
                    ->visible(function (Website $record) {
                        $latestCheck = $record->latestSeoCheck;

                        return $latestCheck && in_array($latestCheck->status, ['running', 'pending']);
                    }),
                \Filament\Actions\Action::make('run_seo_crawl')
                    ->label('Run SEO Check')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Start SEO Health Check')
                    ->modalDescription('This will start a comprehensive SEO health check for this website. The process may take several minutes depending on the site size. The crawler will respect robots.txt and use sitemap.xml if available.')
                    ->action(function (Website $record) {
                        try {
                            $seoService = app(SeoHealthCheckService::class);
                            $seoCheck = $seoService->startManualCheck($record);

                            Notification::make()
                                ->title('SEO Check Started')
                                ->body("SEO health check has been started for {$record->name}. You can monitor progress in real-time.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Error Starting SEO Check')
                                ->body('Failed to start SEO check: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(function (Website $record) {
                        $latestCheck = $record->latestSeoCheck;

                        // Show if no check exists or if the latest check is not running/pending
                        return ! $latestCheck || ! in_array($latestCheck->status, ['running', 'pending']);
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                    \Filament\Actions\ForceDeleteBulkAction::make(),
                    \Filament\Actions\RestoreBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListWebsites::route('/'),
            'create' => Pages\CreateWebsite::route('/create'),
            'edit' => Pages\EditWebsite::route('/{record}/edit'),
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
                'latestSeoCheck:id,website_id,status,started_at,total_urls_crawled,total_crawlable_urls,progress',
            ])
            ->where('created_by', auth()->id())
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
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
