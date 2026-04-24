<?php

namespace App\Filament\Resources\ProjectComponents\Tables;

use App\Models\ProjectComponent;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                SelectFilter::make('current_status')
                    ->label('Current Status')
                    ->options([
                        'healthy' => 'Healthy',
                        'warning' => 'Warning',
                        'danger' => 'Danger',
                        'unknown' => 'Unknown',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        if ($value === 'unknown') {
                            return $query->whereNull('current_status');
                        }

                        return $query->where('current_status', $value);
                    }),
                Filter::make('only_failing')
                    ->label('Show only failing')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn('current_status', ['warning', 'danger'])),
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
