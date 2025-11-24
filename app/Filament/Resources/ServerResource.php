<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServerResource\Pages;
use App\Filament\Resources\ServerResource\RelationManagers;
use App\Models\Server;
use App\Models\ServerInformationHistory;
use App\Models\ServerLogFileHistory;
use App\Tables\Columns\UsageBarColumn;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

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
        return auth()->check();
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
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function ($record) {
                        $latestInfo = $record->latest_server_history_created_at ?? null;

                        $statusColor = 'bg-danger-500';
                        $title = 'Offline (No recent data)';

                        if ($latestInfo && Carbon::parse($latestInfo)->diffInMinutes(now()) <= 2) {
                            $statusColor = 'bg-success-500';
                            $title = 'Online';
                        }

                        return new \Illuminate\Support\HtmlString("
                            <div class='flex items-center gap-2'>
                                <span class='flex-shrink-0 w-2 h-2 rounded-full {$statusColor}' title='{$title}'></span>
                                <span>{$record->name}</span>
                            </div>
                        ");
                    })
                    ->html(),
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->searchable(),
                UsageBarColumn::make('disk_usage')
                    ->label('Disk Usage')
                    ->translateLabel()
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
                    ->state(function (Server $record): array {
                        $info = $record->latest_server_history_info ?? null;
                        $latestInfo = $record->parseLatestServerHistoryInfo($info);

                        if (empty($latestInfo) || ! isset($latestInfo['disk_usage'])) {
                            return [
                                'value' => 0,
                                'tooltip' => 'No data available',
                            ];
                        }

                        $freePercentage = (float) str_replace(['%', ' '], '', $latestInfo['disk_usage']);
                        $usedPercentage = 100 - $freePercentage;

                        return [
                            'value' => $usedPercentage,
                            'tooltip' => sprintf("Used: %.1f%%\nFree: %.1f%%", $usedPercentage, $freePercentage),
                        ];
                    }),
                UsageBarColumn::make('ram_usage')
                    ->label('RAM Usage')
                    ->translateLabel()
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
                    ->state(function (Server $record): array {
                        $info = $record->latest_server_history_info ?? null;
                        $latestInfo = $record->parseLatestServerHistoryInfo($info);

                        if (empty($latestInfo) || ! isset($latestInfo['ram_usage'])) {
                            return [
                                'label' => 'RAM',
                                'value' => 0,
                                'tooltip' => 'No data available',
                            ];
                        }

                        $freePercentage = (float) str_replace(['%', ' '], '', $latestInfo['ram_usage']);
                        $usedPercentage = 100 - $freePercentage;

                        return [
                            'label' => 'RAM',
                            'value' => $usedPercentage,
                            'tooltip' => sprintf("Used: %.1f%%\nFree: %.1f%%", $usedPercentage, $freePercentage),
                        ];
                    }),
                UsageBarColumn::make('cpu_usage')
                    ->label('CPU Load')
                    ->translateLabel()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->orderBy(
                                ServerInformationHistory::select('cpu_load')
                                    ->whereColumn('server_id', 'servers.id')
                                    ->latest()
                                    ->take(1),
                                $direction
                            );
                    })
                    ->state(function (Server $record): array {
                        $info = $record->latest_server_history_info ?? null;
                        $latestInfo = $record->parseLatestServerHistoryInfo($info);

                        if (empty($latestInfo) || ! isset($latestInfo['cpu_usage'])) {
                            return [
                                'value' => 0,
                                'tooltip' => 'No data available',
                            ];
                        }

                        // Get CPU usage directly from CPU_LOAD
                        $cpuUsage = (float) str_replace(',', '.', $latestInfo['cpu_usage']);

                        return [
                            'value' => min(100, $cpuUsage), // Cap at 100%
                            'tooltip' => sprintf(
                                "CPU Load: %.1f%%\nCores: %d",
                                $cpuUsage,
                                $record->cpu_cores ?? 0
                            ),
                        ];
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                \Filament\Actions\Action::make('copy_script')
                    ->label(__('Copy script'))
                    ->icon('heroicon-o-clipboard-document')
                    ->requiresConfirmation(false)
                    ->action(fn() => null)
                    ->extraAttributes(function (Server $record) {
                        $script = ServerInformationHistory::copyCommand($record->id);

                        return [
                            'x-data' => '',
                            'x-on:click.prevent' => new HtmlString(
                                'window.navigator.clipboard.writeText(' . Js::from($script) . '); ' .
                                    '$tooltip(' . Js::from(__('Script copied to clipboard')) . ');'
                            ),
                        ];
                    }),
                \Filament\Actions\Action::make('copy_log_script')
                    ->label(__('Copy log script'))
                    ->icon('heroicon-o-clipboard-document')
                    ->requiresConfirmation(false)
                    ->action(fn() => null)
                    ->extraAttributes(function (Server $record) {
                        $script = ServerLogFileHistory::copyCommand($record->id);

                        return [
                            'x-data' => '',
                            'x-on:click.prevent' => new HtmlString(
                                'window.navigator.clipboard.writeText(' . Js::from($script) . '); ' .
                                    '$tooltip(' . Js::from(__('Log script copied to clipboard')) . ');'
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
            ->withLatestHistory()
            ->where('created_by', auth()->id())
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->url('localhsot'),
            Action::make('delete')
                ->requiresConfirmation()
                ->action(fn() => $this->post->delete()),
        ];
    }

    public static function getTableActions(): array
    {
        return [
            \Filament\Actions\EditAction::make(),
            \Filament\Actions\DeleteAction::make(),
        ];
    }
}
