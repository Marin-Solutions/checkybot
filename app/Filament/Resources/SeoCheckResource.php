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

    protected static ?int $navigationSort = 2;

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
                Tables\Columns\TextColumn::make('health_score')
                    ->label('Health Score')
                    ->formatStateUsing(fn($state) => $state ? $state . '%' : 'N/A')
                    ->badge()
                    ->color(function ($state) {
                        if (! $state) {
                            return 'gray';
                        }
                        if ($state >= 80) {
                            return 'success';
                        }
                        if ($state >= 60) {
                            return 'warning';
                        }

                        return 'danger';
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_urls_crawled')
                    ->label('URLs Crawled')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('errors_found')
                    ->label('Errors')
                    ->badge()
                    ->color('danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('warnings_found')
                    ->label('Warnings')
                    ->badge()
                    ->color('warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('notices_found')
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
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
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
}
