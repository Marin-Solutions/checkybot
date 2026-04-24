<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\MonitorApis;
use App\Support\PackageCheckTableEvidence;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PackageManagedApisRelationManager extends RelationManager
{
    protected static string $relationship = 'packageManagedApis';

    protected static ?string $title = 'Package-managed APIs';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('url')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('current_status')
                    ->label('Health')
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('deleted_at')
                    ->label('State')
                    ->state(fn (MonitorApis $record): string => $record->deleted_at ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
                TextColumn::make('status_summary')
                    ->label('Summary')
                    ->wrap()
                    ->limit(90)
                    ->default('-'),
                TextColumn::make('last_heartbeat_at')
                    ->label('Last Heartbeat')
                    ->state(fn (MonitorApis $record): ?string => $record->last_heartbeat_at?->toDayDateTimeString())
                    ->description(fn (MonitorApis $record): ?string => $record->last_heartbeat_at?->diffForHumans())
                    ->default('-'),
                TextColumn::make('freshness_evidence')
                    ->label('Freshness')
                    ->state(fn (MonitorApis $record): string => PackageCheckTableEvidence::freshnessState($record))
                    ->badge()
                    ->color(fn (string $state): string => PackageCheckTableEvidence::freshnessColor($state))
                    ->description(fn (MonitorApis $record): ?string => PackageCheckTableEvidence::freshnessDescription($record)),
                TextColumn::make('package_interval')
                    ->label('Interval'),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withoutGlobalScopes([
                    SoftDeletingScope::class,
                ]))
            ->defaultSort('title');
    }
}
