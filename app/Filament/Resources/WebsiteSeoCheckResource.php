<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebsiteSeoCheckResource\Pages;
use App\Models\Website;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WebsiteSeoCheckResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static \UnitEnum|string|null $navigationGroup = 'SEO';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'SEO Checks';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Website')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function ($state, $record) {
                        $latestCheck = $record->latestSeoCheck;
                        if ($latestCheck && in_array($latestCheck->status, ['running', 'pending'])) {
                            return $state.' 🔄';
                        }

                        return $state;
                    }),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->url(fn ($record) => $record->url)
                    ->openUrlInNewTab()
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('latest_seo_check_status')
                    ->label('Latest Status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'completed' => 'success',
                        'running' => 'warning',
                        'failed' => 'danger',
                        'cancelled' => 'gray',
                        'pending' => 'gray',
                        null => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('No checks'),
                Tables\Columns\TextColumn::make('seoSchedule.is_active')
                    ->label('SEO Schedule')
                    ->badge()
                    ->formatStateUsing(function ($state, Website $record): string {
                        if (! $record->seoSchedule) {
                            return 'Not configured';
                        }

                        return $state ? 'Enabled' : 'Disabled';
                    })
                    ->color(function ($state, Website $record): string {
                        if (! $record->seoSchedule) {
                            return 'gray';
                        }

                        return $state ? 'success' : 'warning';
                    }),
                Tables\Columns\TextColumn::make('seoSchedule.frequency')
                    ->label('Frequency')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Not configured')
                    ->color(fn (?string $state): string => $state ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('seoSchedule.next_run_at')
                    ->label('Next Run')
                    ->dateTimeInUserZone()
                    ->sortable()
                    ->placeholder('Not scheduled'),
                Tables\Columns\TextColumn::make('latestSeoCheck.total_urls_crawled')
                    ->label('URLs Crawled')
                    ->numeric(),
                Tables\Columns\TextColumn::make('latestSeoCheck.health_score')
                    ->label('Health Score')
                    ->badge()
                    ->color(function ($record): string {
                        $seoCheck = $record->latestSeoCheck;
                        if (! $seoCheck || $seoCheck->total_urls_crawled === 0) {
                            return 'gray';
                        }

                        // Use computed health score directly (most efficient)
                        $score = $seoCheck->computed_health_score ?? 0;

                        if ($score >= 90) {
                            return 'success'; // Green - Excellent (90-100%)
                        }
                        if ($score >= 70) {
                            return 'warning'; // Yellow - Good (70-89%)
                        }
                        if ($score >= 31) {
                            return 'info'; // Orange/Blue - Fair (31-69%)
                        }

                        return 'danger'; // Red - Poor (0-30%)
                    })
                    ->formatStateUsing(function ($record): string {
                        $seoCheck = $record->latestSeoCheck;
                        if (! $seoCheck || $seoCheck->total_urls_crawled === 0) {
                            return 'N/A';
                        }

                        // Use computed health score directly (most efficient)
                        $score = $seoCheck->computed_health_score ?? 0;

                        return number_format($score, 1).'%';
                    }),
                Tables\Columns\TextColumn::make('latestSeoCheck.computed_errors_count')
                    ->label('Errors')
                    ->badge()
                    ->color('danger')
                    ->getStateUsing(function ($record) {
                        return $record->latestSeoCheck?->computed_errors_count ?? 0;
                    }),
                Tables\Columns\TextColumn::make('latestSeoCheck.computed_warnings_count')
                    ->label('Warnings')
                    ->badge()
                    ->color('warning')
                    ->getStateUsing(function ($record) {
                        return $record->latestSeoCheck?->computed_warnings_count ?? 0;
                    }),
                Tables\Columns\TextColumn::make('latestSeoCheck.computed_notices_count')
                    ->label('Notices')
                    ->badge()
                    ->color('info')
                    ->getStateUsing(function ($record) {
                        return $record->latestSeoCheck?->computed_notices_count ?? 0;
                    }),
                Tables\Columns\TextColumn::make('latestSeoCheck.finished_at')
                    ->label('Last Check')
                    ->dateTimeInUserZone()
                    ->sortable(),
                Tables\Columns\TextColumn::make('seo_checks_count')
                    ->label('Total Checks')
                    ->counts('seoChecks')
                    ->numeric(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('latest_seo_check_status')
                    ->label('Latest Status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! $data['value']) {
                            return $query;
                        }

                        return $query->whereHas('latestSeoCheck', function (Builder $query) use ($data) {
                            $query->where('status', $data['value']);
                        });
                    }),
                Tables\Filters\SelectFilter::make('seo_schedule_state')
                    ->label('SEO Schedule')
                    ->options([
                        'enabled' => 'Enabled',
                        'disabled' => 'Disabled',
                        'not_configured' => 'Not configured',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! $data['value']) {
                            return $query;
                        }

                        return match ($data['value']) {
                            'enabled' => $query->whereHas('seoSchedule', function (Builder $query) {
                                $query->where('is_active', true);
                            }),
                            'disabled' => $query->whereHas('seoSchedule', function (Builder $query) {
                                $query->where('is_active', false);
                            }),
                            'not_configured' => $query->whereDoesntHave('seoSchedule'),
                            default => $query,
                        };
                    }),
                Tables\Filters\SelectFilter::make('seo_schedule_frequency')
                    ->label('Frequency')
                    ->options([
                        'daily' => 'Daily',
                        'weekly' => 'Weekly',
                        'monthly' => 'Monthly',
                        'not_configured' => 'Not configured',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! $data['value']) {
                            return $query;
                        }

                        if ($data['value'] === 'not_configured') {
                            return $query->whereDoesntHave('seoSchedule');
                        }

                        return $query->whereHas('seoSchedule', function (Builder $query) use ($data) {
                            $query->where('frequency', $data['value']);
                        });
                    }),
                Tables\Filters\SelectFilter::make('seo_schedule_next_run')
                    ->label('Next Run')
                    ->options([
                        'overdue' => 'Due now or overdue',
                        'next_24_hours' => 'Next 24 hours',
                        'next_7_days' => 'Next 7 days',
                        'not_scheduled' => 'Not scheduled',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! $data['value']) {
                            return $query;
                        }

                        return match ($data['value']) {
                            'overdue' => $query->whereHas('seoSchedule', function (Builder $query) {
                                $query->where('is_active', true)
                                    ->whereNotNull('next_run_at')
                                    ->where('next_run_at', '<=', now());
                            }),
                            'next_24_hours' => $query->whereHas('seoSchedule', function (Builder $query) {
                                $query->where('is_active', true)
                                    ->whereBetween('next_run_at', [now(), now()->addDay()]);
                            }),
                            'next_7_days' => $query->whereHas('seoSchedule', function (Builder $query) {
                                $query->where('is_active', true)
                                    ->whereBetween('next_run_at', [now(), now()->addWeek()]);
                            }),
                            'not_scheduled' => $query->where(function (Builder $query) {
                                $query->whereDoesntHave('seoSchedule')
                                    ->orWhereHas('seoSchedule', function (Builder $query) {
                                        $query->whereNull('next_run_at');
                                    });
                            }),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('view_checks')
                    ->label('View Checks')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => route('filament.admin.resources.seo-checks.index', ['website_id' => $record->id]))
                    ->openUrlInNewTab(),
                \Filament\Actions\Action::make('view_latest_progress')
                    ->label('View Progress')
                    ->icon('heroicon-o-chart-bar')
                    ->color('warning')
                    ->url(function ($record) {
                        $latestCheck = $record->latestSeoCheck;
                        if ($latestCheck) {
                            return "/admin/seo-checks/{$latestCheck->id}";
                        }

                        return null;
                    })
                    ->openUrlInNewTab()
                    ->visible(function ($record) {
                        $latestCheck = $record->latestSeoCheck;

                        return $latestCheck && in_array($latestCheck->status, ['running', 'pending']);
                    }),
                \Filament\Actions\Action::make('run_seo_check')
                    ->label('Run SEO Check')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Start SEO Health Check')
                    ->modalDescription('This will start a comprehensive SEO health check for this website. The process may take several minutes depending on the site size.')
                    ->action(function ($record) {
                        try {
                            $seoService = app(\App\Services\SeoHealthCheckService::class);
                            $seoCheck = $seoService->startManualCheck($record);

                            \Filament\Notifications\Notification::make()
                                ->title('SEO Check Started')
                                ->body("SEO health check has been started for {$record->name}. You can monitor the progress in real-time.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Error Starting SEO Check')
                                ->body('Failed to start SEO check: '.$e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->visible(function ($record) {
                        $latestCheck = $record->latestSeoCheck;

                        // Show if no check exists or if the latest check is not running/pending
                        return ! $latestCheck || ! in_array($latestCheck->status, ['running', 'pending']);
                    }),
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
            'index' => Pages\ListWebsiteSeoChecks::route('/'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'seoSchedule',
                'latestSeoCheck' => function ($query) {
                    $query->withCount([
                        'seoIssues as errors_count' => function ($query) {
                            $query->where('severity', 'error');
                        },
                        'seoIssues as warnings_count' => function ($query) {
                            $query->where('severity', 'warning');
                        },
                        'seoIssues as notices_count' => function ($query) {
                            $query->where('severity', 'notice');
                        },
                        'crawlResults as http_errors_count' => function ($query) {
                            $query->where('status_code', '>=', 400)
                                ->where('status_code', '<', 600);
                        },
                    ]);
                },
            ])
            ->where('created_by', auth()->id())
            ->orderBy('created_at', 'desc');
    }
}
