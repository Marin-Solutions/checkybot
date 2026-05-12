<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\ProjectComponents\Tables\ProjectComponentsTable;
use App\Filament\Support\HealthStatusFilter;
use App\Models\ProjectComponent;
use App\Support\HealthStatusLabel;
use App\Support\ProjectComponentDeliveryState;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('declared_interval')
                    ->label('Interval'),
                Tables\Columns\TextColumn::make('delivery_state')
                    ->label('Delivery State')
                    ->state(fn (ProjectComponent $record): string => ProjectComponentDeliveryState::label($record))
                    ->badge()
                    ->color(fn (string $state): string => ProjectComponentDeliveryState::color($state)),
                Tables\Columns\TextColumn::make('summary')
                    ->wrap()
                    ->limit(80),
                Tables\Columns\TextColumn::make('last_heartbeat_at')
                    ->sinceInUserZone(),
            ])
            ->filters([
                HealthStatusFilter::makeForNonNullableColumn(),
                Tables\Filters\SelectFilter::make('delivery_state')
                    ->label('Delivery State')
                    ->options(ProjectComponentDeliveryState::options())
                    ->query(fn (Builder $query, array $data): Builder => ProjectComponentDeliveryState::applyFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
                HealthStatusFilter::onlyFailing(
                    activeScope: fn (Builder $query): Builder => $query->where('is_archived', false),
                ),
            ])
            ->recordActions(ProjectComponentsTable::recordActions(includeEdit: false))
            ->toolbarActions([
                BulkActionGroup::make(ProjectComponentsTable::bulkActions(includeDelete: false)),
            ])
            ->defaultSort('name');
    }
}
