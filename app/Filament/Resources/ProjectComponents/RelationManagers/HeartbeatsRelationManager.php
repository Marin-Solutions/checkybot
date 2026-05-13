<?php

namespace App\Filament\Resources\ProjectComponents\RelationManagers;

use App\Models\ProjectComponentHeartbeat;
use App\Support\MetricsPayloadFormatter;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HeartbeatsRelationManager extends RelationManager
{
    protected static string $relationship = 'heartbeats';

    protected static ?string $title = 'Heartbeat History';

    protected static ?string $recordTitleAttribute = 'observed_at';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('event')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::eventLabel($state))
                    ->color(fn (?string $state): string => self::eventColor($state)),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                    ->color(fn (?string $state): string => self::statusColor($state)),
                Tables\Columns\TextColumn::make('summary')
                    ->wrap()
                    ->limit(120),
                Tables\Columns\TextColumn::make('metrics')
                    ->label('Metrics')
                    ->state(fn (ProjectComponentHeartbeat $record): string => MetricsPayloadFormatter::format($record->metrics))
                    ->fontFamily('mono')
                    ->limit(120)
                    ->tooltip(fn (ProjectComponentHeartbeat $record): string => MetricsPayloadFormatter::format($record->metrics)),
                Tables\Columns\TextColumn::make('observed_at')
                    ->dateTimeInUserZone()
                    ->sortable()
                    ->description(fn ($record): ?string => $record->observed_at?->diffForHumans()),
            ])
            ->defaultSort('observed_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'healthy' => 'Healthy',
                        'warning' => 'Warning',
                        'danger' => 'Danger',
                    ]),
                Tables\Filters\SelectFilter::make('event')
                    ->label('Event')
                    ->options([
                        'heartbeat' => 'Heartbeat',
                        'stale' => 'Stale',
                    ]),
                Tables\Filters\Filter::make('stale_only')
                    ->label('Stale only')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('event', 'stale')),
                Tables\Filters\Filter::make('metrics_present')
                    ->label('Metrics present')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => self::applyMetricsPresentFilter($query)),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('View Evidence')
                    ->modalHeading('Heartbeat Evidence')
                    ->modalWidth('4xl'),
            ])
            ->bulkActions([]);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Evidence Summary')
                    ->schema([
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => self::statusLabel($state))
                            ->color(fn (?string $state): string => self::statusColor($state)),
                        TextEntry::make('event')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => self::eventLabel($state))
                            ->color(fn (?string $state): string => self::eventColor($state)),
                        TextEntry::make('component_name')
                            ->label('Component')
                            ->copyable(),
                        TextEntry::make('observed_at')
                            ->label('Observed At')
                            ->dateTimeInUserZone(),
                        TextEntry::make('created_at')
                            ->label('Recorded At')
                            ->dateTimeInUserZone(),
                        TextEntry::make('summary')
                            ->default('-')
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('Metrics Snapshot')
                    ->hidden(fn (ProjectComponentHeartbeat $record): bool => blank($record->metrics))
                    ->schema([
                        TextEntry::make('metrics')
                            ->label('')
                            ->state(fn (ProjectComponentHeartbeat $record): string => MetricsPayloadFormatter::format($record->metrics))
                            ->fontFamily('mono')
                            ->copyable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function applyMetricsPresentFilter(Builder $query): Builder
    {
        $wrappedMetricsColumn = $query->getQuery()->getGrammar()->wrap('metrics');

        return $query
            ->whereNotNull('metrics')
            ->where(function (Builder $query) use ($wrappedMetricsColumn): void {
                match ($query->getConnection()->getDriverName()) {
                    'mysql', 'mariadb' => $query->whereRaw("JSON_LENGTH({$wrappedMetricsColumn}) > 0"),
                    'pgsql' => $query->whereRaw("({$wrappedMetricsColumn})::text not in ('[]', '{}')"),
                    'sqlsrv' => $query->whereRaw("CAST({$wrappedMetricsColumn} AS nvarchar(max)) not in ('[]', '{}')"),
                    default => $query->whereNotIn('metrics', ['[]', '{}']),
                };
            });
    }

    private static function statusLabel(?string $status): string
    {
        return $status ? ucfirst($status) : 'Unknown';
    }

    private static function statusColor(?string $status): string
    {
        return match ($status) {
            'healthy' => 'success',
            'warning' => 'warning',
            'danger' => 'danger',
            default => 'gray',
        };
    }

    private static function eventLabel(?string $event): string
    {
        return match ($event) {
            'heartbeat' => 'Heartbeat',
            'stale' => 'Stale',
            default => $event ? ucfirst($event) : 'Unknown',
        };
    }

    private static function eventColor(?string $event): string
    {
        return match ($event) {
            'heartbeat' => 'gray',
            'stale' => 'danger',
            default => 'gray',
        };
    }
}
