<?php

namespace App\Filament\Resources\WebsiteResource\RelationManagers;

use App\Enums\RunSource;
use App\Support\UptimeTransportError;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LogHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'logHistory';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('created_at')
            ->columns([
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('run_source')
                    ->label('Run')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => RunSource::coerce($state)->label())
                    ->color(fn (mixed $state): string => RunSource::coerce($state)->color()),
                TextColumn::make('http_status_code')
                    ->label('HTTP'),
                TextColumn::make('transport_error_type')
                    ->label('Transport Error')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => UptimeTransportError::label($state))
                    ->color(fn (?string $state): string => UptimeTransportError::color($state))
                    ->placeholder('-'),
                TextColumn::make('transport_error_message')
                    ->label('Transport Evidence')
                    ->limit(80)
                    ->wrap()
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('-'),
                TextColumn::make('speed')
                    ->label('Response Time')
                    ->formatStateUsing(fn (?int $state): string => $state ? "{$state}ms" : '-'),
                TextColumn::make('summary')
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('created_at')
                    ->dateTimeInUserZone()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'healthy' => 'Healthy',
                        'warning' => 'Warning',
                        'danger' => 'Danger',
                    ]),
                Tables\Filters\SelectFilter::make('run_source')
                    ->label('Run')
                    ->options(RunSource::options()),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
