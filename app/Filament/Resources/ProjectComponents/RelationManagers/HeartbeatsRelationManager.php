<?php

namespace App\Filament\Resources\ProjectComponents\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class HeartbeatsRelationManager extends RelationManager
{
    protected static string $relationship = 'heartbeats';

    protected static ?string $title = 'Heartbeat History';

    protected static ?string $recordTitleAttribute = 'observed_at';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('event')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'stale' ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('summary')
                    ->wrap()
                    ->limit(120),
                Tables\Columns\TextColumn::make('metrics')
                    ->label('Metrics')
                    ->state(fn ($record): string => static::formatMetricsForDisplay($record->metrics))
                    ->fontFamily('mono')
                    ->wrap(),
                Tables\Columns\TextColumn::make('observed_at')
                    ->dateTime()
                    ->sortable()
                    ->description(fn ($record): ?string => $record->observed_at?->diffForHumans()),
            ])
            ->defaultSort('observed_at', 'desc');
    }

    /**
     * @param  array<string, mixed>|null  $metrics
     */
    protected static function formatMetricsForDisplay(?array $metrics): string
    {
        if (blank($metrics)) {
            return '{}';
        }

        return json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
