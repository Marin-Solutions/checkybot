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

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationGroup = 'SEO';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'SEO Checks';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Website')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->url(fn($record) => $record->url)
                    ->openUrlInNewTab()
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('latest_seo_check_status')
                    ->label('Latest Status')
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'completed' => 'success',
                        'running' => 'warning',
                        'failed' => 'danger',
                        'pending' => 'gray',
                        null => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('No checks'),
                Tables\Columns\TextColumn::make('latest_seo_check_urls_crawled')
                    ->label('URLs Crawled')
                    ->numeric(),
                Tables\Columns\TextColumn::make('latest_seo_check_errors_count')
                    ->label('Errors')
                    ->badge()
                    ->color('danger'),
                Tables\Columns\TextColumn::make('latest_seo_check_warnings_count')
                    ->label('Warnings')
                    ->badge()
                    ->color('warning'),
                Tables\Columns\TextColumn::make('latest_seo_check_notices_count')
                    ->label('Notices')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('latest_seo_check_finished_at')
                    ->label('Last Check')
                    ->dateTime()
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
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (!$data['value']) {
                            return $query;
                        }

                        return $query->whereHas('latestSeoCheck', function (Builder $query) use ($data) {
                            $query->where('status', $data['value']);
                        });
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('view_checks')
                    ->label('View Checks')
                    ->icon('heroicon-o-eye')
                    ->url(fn($record) => route('filament.admin.resources.seo-checks.index', ['website_id' => $record->id]))
                    ->openUrlInNewTab(),
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
            ->with(['latestSeoCheck'])
            ->has('seoChecks') // Only show websites that have SEO checks
            ->orderBy('created_at', 'desc');
    }
}
