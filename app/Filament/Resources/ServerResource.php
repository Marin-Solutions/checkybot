<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServerResource\Pages;
use App\Filament\Resources\ServerResource\RelationManagers;
use App\Models\Server;
use App\Models\ServerInformationHistory;
use App\Models\ServerLogFileHistory;
use App\Support\UserTimezone;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use RyanChandler\FilamentProgressColumn\ProgressColumn;

class ServerResource extends Resource
{
    protected static ?string $model = Server::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-server-stack';

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    /**
     * Get the navigation badge for the resource.
     */
    public static function getNavigationBadge(): ?string
    {
        return number_format(static::getModel()::where('created_by', auth()->id())->count());
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['Super Admin', 'Admin']) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('ip')
                    ->required()
                    ->ip()
                    ->maxLength(255),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('description')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withLatestHistory())
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(function ($record) {
                        if ($record->hasFreshLatestHistory()) {
                            return 'Online';
                        }

                        return 'Offline';
                    })
                    ->icon(fn (string $state): string => $state === 'Online' ? 'heroicon-o-signal' : 'heroicon-o-signal-slash')
                    ->color(fn (string $state): string => $state === 'Online' ? 'success' : 'danger')
                    ->tooltip(function ($record) {
                        $latestInfo = $record->latest_server_history_created_at ?? null;

                        if (! $latestInfo) {
                            return 'No data received';
                        }

                        return 'Last update '.Carbon::parse($latestInfo)->diffForHumans();
                    }),
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->searchable(),
                ProgressColumn::make('disk_usage')
                    ->label('Disk Usage')
                    ->translateLabel()
                    ->view('filament.tables.columns.server-metric-progress')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->orderBy(
                                ServerInformationHistory::select('disk_free_percentage')
                                    ->whereColumn('server_id', 'servers.id')
                                    ->latest()
                                    ->take(1),
                                $direction
                            );
                    })
                    ->tooltip(fn (Server $record): string => self::serverMetricFreshnessTooltip($record))
                    ->progress(function (Server $record): float {
                        if (! $record->hasFreshLatestHistory()) {
                            return 0;
                        }

                        $latestInfo = $record->parseLatestServerHistoryInfo($record->latest_server_history_info);

                        if (! isset($latestInfo['disk_usage'])) {
                            return 0;
                        }

                        $freePercentage = (float) str_replace(['%', ' '], '', $latestInfo['disk_usage']);
                        $usedPercentage = 100 - $freePercentage;

                        return max(0, min(100, $usedPercentage));
                    })
                    ->color(function (Server $record): string {
                        if (! $record->hasFreshLatestHistory()) {
                            return 'gray';
                        }

                        $latestInfo = $record->parseLatestServerHistoryInfo($record->latest_server_history_info);

                        if (! isset($latestInfo['disk_usage'])) {
                            return 'gray';
                        }

                        $freePercentage = (float) str_replace(['%', ' '], '', $latestInfo['disk_usage']);
                        $usedPercentage = 100 - $freePercentage;

                        return match (true) {
                            $usedPercentage >= 90 => 'danger',
                            $usedPercentage >= 75 => 'warning',
                            default => 'success',
                        };
                    }),
                ProgressColumn::make('ram_usage')
                    ->label('RAM Usage')
                    ->translateLabel()
                    ->view('filament.tables.columns.server-metric-progress')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->orderBy(
                                ServerInformationHistory::select('ram_free_percentage')
                                    ->whereColumn('server_id', 'servers.id')
                                    ->latest()
                                    ->take(1),
                                $direction
                            );
                    })
                    ->tooltip(fn (Server $record): string => self::serverMetricFreshnessTooltip($record))
                    ->progress(function (Server $record): float {
                        if (! $record->hasFreshLatestHistory()) {
                            return 0;
                        }

                        $latestInfo = $record->parseLatestServerHistoryInfo($record->latest_server_history_info);

                        if (! isset($latestInfo['ram_usage'])) {
                            return 0;
                        }

                        $freePercentage = (float) str_replace(['%', ' '], '', $latestInfo['ram_usage']);
                        $usedPercentage = 100 - $freePercentage;

                        return max(0, min(100, $usedPercentage));
                    })
                    ->color(function (Server $record): string {
                        if (! $record->hasFreshLatestHistory()) {
                            return 'gray';
                        }

                        $latestInfo = $record->parseLatestServerHistoryInfo($record->latest_server_history_info);

                        if (! isset($latestInfo['ram_usage'])) {
                            return 'gray';
                        }

                        $freePercentage = (float) str_replace(['%', ' '], '', $latestInfo['ram_usage']);
                        $usedPercentage = 100 - $freePercentage;

                        return match (true) {
                            $usedPercentage >= 90 => 'danger',
                            $usedPercentage >= 75 => 'warning',
                            default => 'success',
                        };
                    }),
                ProgressColumn::make('cpu_usage')
                    ->label('CPU Load')
                    ->translateLabel()
                    ->view('filament.tables.columns.server-metric-progress')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->orderBy(
                                ServerInformationHistory::selectRaw("
                                    (CAST(REPLACE(cpu_load, ',', '.') AS DECIMAL(10, 4)) /
                                        CASE
                                            WHEN servers.cpu_cores IS NULL OR servers.cpu_cores < 1 THEN 1
                                            ELSE servers.cpu_cores
                                        END
                                    ) * 100
                                ")
                                    ->whereColumn('server_id', 'servers.id')
                                    ->latest()
                                    ->take(1),
                                $direction
                            );
                    })
                    ->tooltip(fn (Server $record): string => self::serverMetricFreshnessTooltip($record))
                    ->progress(function (Server $record): float {
                        if (! $record->hasFreshLatestHistory()) {
                            return 0;
                        }

                        $latestInfo = $record->parseLatestServerHistoryInfo($record->latest_server_history_info);

                        if (! isset($latestInfo['cpu_usage'])) {
                            return 0;
                        }

                        $cpuUsage = $record->cpuLoadToUsagePercentage($latestInfo['cpu_usage']);

                        return max(0, min(100, $cpuUsage));
                    })
                    ->color(function (Server $record): string {
                        if (! $record->hasFreshLatestHistory()) {
                            return 'gray';
                        }

                        $latestInfo = $record->parseLatestServerHistoryInfo($record->latest_server_history_info);

                        if (! isset($latestInfo['cpu_usage'])) {
                            return 'gray';
                        }

                        $cpuUsage = $record->cpuLoadToUsagePercentage($latestInfo['cpu_usage']);

                        return match (true) {
                            $cpuUsage >= 90 => 'danger',
                            $cpuUsage >= 75 => 'warning',
                            default => 'success',
                        };
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTimeInUserZone()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTimeInUserZone()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTimeInUserZone()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('server_attention')
                    ->label('Server Attention')
                    ->options([
                        'offline_reporters' => 'Offline reporters',
                        'stale_metrics' => 'Stale metrics',
                        'warning_usage' => 'Warning usage',
                        'critical_usage' => 'Critical usage',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return match ($value) {
                            'offline_reporters' => self::whereOfflineReporter($query),
                            'stale_metrics' => self::whereStaleMetrics($query),
                            'warning_usage' => self::whereWarningUsage($query),
                            'critical_usage' => self::whereCriticalUsage($query),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                \Filament\Actions\Action::make('copy_script')
                    ->label(__('Copy script'))
                    ->icon('heroicon-o-clipboard-document')
                    ->requiresConfirmation(false)
                    ->action(fn () => null)
                    ->extraAttributes(function (Server $record) {
                        $script = ServerInformationHistory::copyCommand($record->id);

                        return [
                            'x-data' => '',
                            'x-on:click.prevent' => new HtmlString(
                                'window.navigator.clipboard.writeText('.Js::from($script).'); '.
                                    '$tooltip('.Js::from(__('Script copied to clipboard')).');'
                            ),
                        ];
                    }),
                \Filament\Actions\Action::make('copy_log_script')
                    ->label(__('Copy log script'))
                    ->icon('heroicon-o-clipboard-document')
                    ->requiresConfirmation(false)
                    ->action(fn () => null)
                    ->extraAttributes(function (Server $record) {
                        $script = ServerLogFileHistory::copyCommand($record->id);

                        return [
                            'x-data' => '',
                            'x-on:click.prevent' => new HtmlString(
                                'window.navigator.clipboard.writeText('.Js::from($script).'); '.
                                    '$tooltip('.Js::from(__('Log script copied to clipboard')).');'
                            ),
                        ];
                    }),
                \Filament\Actions\ViewAction::make('view_statistics')
                    ->label('View statistics')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->color('warning'),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                    \Filament\Actions\ForceDeleteBulkAction::make(),
                    \Filament\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Server')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('ip')
                            ->label('IP')
                            ->copyable(),
                        TextEntry::make('description')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Reporter Evidence')
                    ->description('Latest reporter request metadata for verifying setup and diagnosing offline servers.')
                    ->schema([
                        TextEntry::make('last_reporter_seen_at')
                            ->label('Last Seen')
                            ->state(fn (Server $record): string => $record->last_reporter_seen_at
                                ? $record->last_reporter_seen_at->timezone(UserTimezone::current() ?? config('app.timezone'))->toDayDateTimeString()
                                : 'Never')
                            ->hint(fn (Server $record): ?string => $record->last_reporter_seen_at?->diffForHumans()),
                        TextEntry::make('last_reporter_ip')
                            ->label('Last Reporter IP')
                            ->default('-')
                            ->copyable(),
                        TextEntry::make('last_reporter_user_agent')
                            ->label('Last Reporter User Agent')
                            ->default('-')
                            ->copyable()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\LogCategoriesRelationManager::class,
            RelationManagers\RulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServers::route('/'),
            'create' => Pages\CreateServer::route('/create'),
            'view' => Pages\LogServer::route('/{record}/log'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('servers.created_by', auth()->id())
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getTableActions(): array
    {
        return [
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ];
    }

    private static function serverMetricFreshnessTooltip(Server $record): string
    {
        if (! $record->latest_server_history_created_at) {
            return 'Metric freshness unknown because no reporter data has been received.';
        }

        if (! $record->hasFreshLatestHistory()) {
            return 'Metrics are stale. Last reporter update '.Carbon::parse($record->latest_server_history_created_at)->diffForHumans().'.';
        }

        return 'Last reporter update '.Carbon::parse($record->latest_server_history_created_at)->diffForHumans().'.';
    }

    private static function whereOfflineReporter(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('sih.id')
                ->orWhere('sih.created_at', '<', self::latestHistoryFreshAfter());
        });
    }

    private static function whereStaleMetrics(Builder $query): Builder
    {
        return $query
            ->whereNotNull('sih.id')
            ->where('sih.created_at', '<', self::latestHistoryFreshAfter());
    }

    private static function whereWarningUsage(Builder $query): Builder
    {
        return self::whereFreshReporter($query)
            ->where(fn (Builder $query) => self::whereAnyMetricWarning($query))
            ->where(fn (Builder $query) => self::whereNoMetricCritical($query));
    }

    private static function whereCriticalUsage(Builder $query): Builder
    {
        return self::whereFreshReporter($query)
            ->where(fn (Builder $query) => self::whereAnyMetricCritical($query));
    }

    private static function whereFreshReporter(Builder $query): Builder
    {
        return $query
            ->whereNotNull('sih.id')
            ->where('sih.created_at', '>=', self::latestHistoryFreshAfter());
    }

    private static function whereAnyMetricWarning(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $query) => self::whereMetricBetween($query, self::diskUsageExpression(), 75, 90))
            ->orWhere(fn (Builder $query) => self::whereMetricBetween($query, self::ramUsageExpression(), 75, 90))
            ->orWhere(fn (Builder $query) => self::whereMetricBetween($query, self::cpuUsageExpression(), 75, 90));
    }

    private static function whereAnyMetricCritical(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $query) => self::whereMetricAtLeast($query, self::diskUsageExpression(), 90))
            ->orWhere(fn (Builder $query) => self::whereMetricAtLeast($query, self::ramUsageExpression(), 90))
            ->orWhere(fn (Builder $query) => self::whereMetricAtLeast($query, self::cpuUsageExpression(), 90));
    }

    private static function whereNoMetricCritical(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $query) => self::whereMetricBelowOrMissing($query, 'sih.disk_free_percentage', self::diskUsageExpression(), 90))
            ->where(fn (Builder $query) => self::whereMetricBelowOrMissing($query, 'sih.ram_free_percentage', self::ramUsageExpression(), 90))
            ->where(fn (Builder $query) => self::whereMetricBelowOrMissing($query, 'sih.cpu_load', self::cpuUsageExpression(), 90));
    }

    private static function whereMetricBetween(Builder $query, string $expression, int $minimum, int $maximum): Builder
    {
        return $query
            ->whereRaw("{$expression} >= ?", [$minimum])
            ->whereRaw("{$expression} < ?", [$maximum]);
    }

    private static function whereMetricAtLeast(Builder $query, string $expression, int $minimum): Builder
    {
        return $query->whereRaw("{$expression} >= ?", [$minimum]);
    }

    private static function whereMetricBelowOrMissing(Builder $query, string $column, string $expression, int $minimum): Builder
    {
        return $query
            ->whereNull($column)
            ->orWhere($column, '')
            ->orWhereRaw("{$expression} < ?", [$minimum]);
    }

    private static function diskUsageExpression(): string
    {
        return "(100 - CAST(REPLACE(REPLACE(sih.disk_free_percentage, '%', ''), ' ', '') AS DECIMAL(10, 4)))";
    }

    private static function ramUsageExpression(): string
    {
        return "(100 - CAST(REPLACE(REPLACE(sih.ram_free_percentage, '%', ''), ' ', '') AS DECIMAL(10, 4)))";
    }

    private static function cpuUsageExpression(): string
    {
        return "(
            CAST(REPLACE(sih.cpu_load, ',', '.') AS DECIMAL(10, 4)) /
            CASE
                WHEN servers.cpu_cores IS NULL OR servers.cpu_cores < 1 THEN 1
                ELSE servers.cpu_cores
            END
        ) * 100";
    }

    private static function latestHistoryFreshAfter(): Carbon
    {
        return now()->subMinutes(Server::REPORTER_FRESHNESS_WINDOW_MINUTES);
    }
}
