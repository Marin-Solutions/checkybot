<?php

namespace App\Filament\Resources\ProjectComponents\Tables;

use App\Filament\Support\HealthStatusFilter;
use App\Models\ProjectComponent;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProjectComponentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('project.name')
                    ->label('Application')
                    ->searchable(),
                TextColumn::make('current_status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('archive_state')
                    ->label('State')
                    ->state(fn (ProjectComponent $record): string => $record->is_archived ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
                TextColumn::make('declared_interval')
                    ->label('Interval'),
                TextColumn::make('last_heartbeat_at')
                    ->since(),
            ])
            ->filters([
                HealthStatusFilter::makeForNonNullableColumn(),
                HealthStatusFilter::onlyFailing(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
