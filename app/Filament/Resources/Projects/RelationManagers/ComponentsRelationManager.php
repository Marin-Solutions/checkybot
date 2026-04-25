<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class ComponentsRelationManager extends RelationManager
{
    protected static string $relationship = 'components';

    protected static ?string $title = 'Components';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('current_status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('declared_interval')
                    ->label('Interval'),
                Tables\Columns\TextColumn::make('archive_state')
                    ->label('State')
                    ->state(fn ($record): string => $record->is_archived ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
                Tables\Columns\TextColumn::make('summary')
                    ->wrap()
                    ->limit(80),
                Tables\Columns\TextColumn::make('last_heartbeat_at')
                    ->sinceInUserZone(),
            ])
            ->recordActions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('name');
    }
}
