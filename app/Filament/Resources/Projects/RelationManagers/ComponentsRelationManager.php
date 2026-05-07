<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Support\ProjectComponentDeliveryState;
use App\Models\ProjectComponent;
use App\Support\HealthStatusLabel;
use Filament\Actions\ViewAction;
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
                    ->state(fn (ProjectComponent $record): string => ProjectComponentDeliveryState::state($record))
                    ->formatStateUsing(fn (string $state): string => ProjectComponentDeliveryState::label($state))
                    ->badge()
                    ->color(fn (string $state): string => ProjectComponentDeliveryState::color($state)),
                Tables\Columns\TextColumn::make('summary')
                    ->wrap()
                    ->limit(80),
                Tables\Columns\TextColumn::make('last_heartbeat_at')
                    ->sinceInUserZone(),
            ])
            ->filters([
                ProjectComponentDeliveryState::filter(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('name');
    }
}
