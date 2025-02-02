<?php

namespace App\Filament\Resources\MonitorApisResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'results';
    protected static ?string $title = 'Monitoring Results';
    protected static ?string $recordTitleAttribute = 'created_at';
    protected static ?string $inverseRelationship = 'monitorApi';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('is_success')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('response_time_ms')
                    ->label('Response Time')
                    ->formatStateUsing(fn($state) => "{$state}ms")
                    ->sortable(),

                Tables\Columns\TextColumn::make('http_code')
                    ->label('HTTP Code')
                    ->badge()
                    ->color(fn($state) => match (true) {
                        $state >= 500 => 'danger',
                        $state >= 400 => 'warning',
                        $state >= 200 && $state < 300 => 'success',
                        default => 'gray'
                    }),

                Tables\Columns\TextColumn::make('failed_assertions')
                    ->label('Failed Assertions')
                    ->visible(fn($record) => $record && !$record->is_success)
                    ->formatStateUsing(function ($state) {
                        if (empty($state)) return '-';
                        return collect($state)->map(function ($assertion) {
                            return "{$assertion['path']} - {$assertion['message']}";
                        })->join("\n");
                    })
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50, 100])
            ->filters([
                Tables\Filters\SelectFilter::make('is_success')
                    ->label('Status')
                    ->options([
                        '1' => 'Success',
                        '0' => 'Failed'
                    ]),
                Tables\Filters\Filter::make('high_response_time')
                    ->label('High Response Time')
                    ->query(fn($query) => $query->where('response_time_ms', '>', 1000)),
            ])
            ->actions([
                // No actions needed for results
            ])
            ->bulkActions([
                // No bulk actions needed
            ]);
    }
}
