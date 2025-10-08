<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SeoCheckResource\Pages;
use App\Models\SeoCheck;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SeoCheckResource extends Resource
{
    protected static ?string $model = SeoCheck::class;

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static ?string $navigationGroup = 'SEO';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'All SEO Checks';

    public static function shouldRegisterNavigation(): bool
    {
        return false; // Hide from navigation, only accessible via WebsiteSeoCheckResource
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('website.name')
                    ->label('Website')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('website.url')
                    ->label('URL')
                    ->url(fn($record) => $record->website->url)
                    ->openUrlInNewTab()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'completed' => 'success',
                        'running' => 'warning',
                        'failed' => 'danger',
                        'pending' => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_urls_crawled')
                    ->label('URLs Crawled')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('health_score_formatted')
                    ->label('Health Score')
                    ->badge()
                    ->color(fn($record): string => $record->health_score_color)
                    ->formatStateUsing(fn($record): string => $record->isCompleted() ? $record->health_score_formatted : 'N/A')
                    ->sortable(query: function ($query, string $direction) {
                        // Custom sorting for health score calculation
                        return $query->orderBy('total_urls_crawled', $direction);
                    }),
                Tables\Columns\TextColumn::make('errors_count')
                    ->label('Errors')
                    ->badge()
                    ->color('danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('warnings_count')
                    ->label('Warnings')
                    ->badge()
                    ->color('warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('notices_count')
                    ->label('Notices')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('finished_at')
                    ->label('Finished')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            // Bulk actions disabled to prevent JavaScript errors
            // ->bulkActions([
            //     Tables\Actions\BulkActionGroup::make([
            //         Tables\Actions\DeleteBulkAction::make(),
            //     ]),
            // ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListSeoChecks::route('/'),
            'view' => Pages\ViewSeoCheck::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()
            ->with(['seoIssues', 'website', 'crawlResults'])
            ->withCount([
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
    }
}
