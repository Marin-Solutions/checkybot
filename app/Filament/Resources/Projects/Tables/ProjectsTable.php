<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Models\Project;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('application_status')
                    ->label('Current Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('environment')
                    ->badge()
                    ->default('Unknown'),
                TextColumn::make('technology')
                    ->default('-'),
                TextColumn::make('components_count')
                    ->label('Components')
                    ->state(fn (Project $record): int => $record->components_count ?? $record->components()->count()),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('application_status')
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

                        // Mirror Project::resolveApplicationStatus(): the project's
                        // status is the worst active component status. Warning and
                        // Healthy therefore require both a positive check (has the
                        // matching status) AND a negation (no worse statuses exist),
                        // so the computed rollup and the filter always agree.
                        return match ($value) {
                            'danger' => $query->whereHas(
                                'activeComponents',
                                fn (Builder $components) => $components->where('current_status', 'danger'),
                            ),
                            'warning' => $query
                                ->whereHas(
                                    'activeComponents',
                                    fn (Builder $components) => $components->where('current_status', 'warning'),
                                )
                                ->whereDoesntHave(
                                    'activeComponents',
                                    fn (Builder $components) => $components->where('current_status', 'danger'),
                                ),
                            'healthy' => $query
                                ->whereHas('activeComponents')
                                ->whereDoesntHave(
                                    'activeComponents',
                                    fn (Builder $components) => $components->whereIn('current_status', ['warning', 'danger']),
                                ),
                            'unknown' => $query->whereDoesntHave('activeComponents'),
                            default => $query,
                        };
                    }),
                Filter::make('only_failing')
                    ->label('Show only failing')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'activeComponents',
                        fn (Builder $components) => $components->whereIn('current_status', ['warning', 'danger']),
                    )),
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
