<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\Website;
use App\Support\PackageCheckTableEvidence;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PackageManagedWebsitesRelationManager extends RelationManager
{
    protected static string $relationship = 'packageManagedWebsites';

    protected static ?string $title = 'Package-managed Websites';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('url')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('check_types')
                    ->label('Checks')
                    ->state(function ($record): string {
                        return collect([
                            $record->uptime_check ? 'Uptime' : null,
                            $record->ssl_check ? 'SSL' : null,
                        ])->filter()->implode(', ');
                    })
                    ->badge(),
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
                    ->state(fn (Website $record): string => $record->deleted_at ? 'Archived' : 'Active')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Archived' ? 'gray' : 'success'),
                TextColumn::make('status_summary')
                    ->label('Summary')
                    ->wrap()
                    ->limit(90)
                    ->default('-'),
                TextColumn::make('last_heartbeat_at')
                    ->label('Last Heartbeat')
                    ->state(fn (Website $record): ?string => $record->last_heartbeat_at?->toDayDateTimeString())
                    ->description(fn (Website $record): ?string => $record->last_heartbeat_at?->diffForHumans())
                    ->default('-'),
                TextColumn::make('freshness_evidence')
                    ->label('Freshness')
                    ->state(fn (Website $record): string => PackageCheckTableEvidence::freshnessState($record))
                    ->badge()
                    ->color(fn (string $state): string => PackageCheckTableEvidence::freshnessColor($state))
                    ->description(fn (Website $record): ?string => PackageCheckTableEvidence::freshnessDescription($record)),
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
            ->defaultSort('name');
    }
}
