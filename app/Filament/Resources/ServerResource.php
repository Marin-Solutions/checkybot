<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServerResource\Pages;
use App\Filament\Resources\ServerResource\RelationManagers;
use App\Models\Server;
use App\Models\ServerInformationHistory;
use App\Models\ServerLogFileHistory;
use App\Tables\Columns\UsageBarColumn;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Webbingbrasil\FilamentCopyActions\Tables\Actions\CopyAction;

class ServerResource extends Resource
{
    protected static ?string $model = Server::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'Operations';

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

    public static function form(Form $form): Form
    {
        return $form
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
                        $latestInfo = $record->latest_server_history_created_at;

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
                        $latestInfo = $record->parseLatestServerHistoryInfo($record->latest_server_history_info);

                        // Debug output
                        \Log::info('Disk Usage Debug', [
                            'server_id' => $record->id,
                            'has_latest_info' => $latestInfo ? 'yes' : 'no',
                            'raw_data' => $latestInfo,
                        ]);

                        if (! isset($latestInfo['disk_usage'])) {
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
                        $latestInfo = $record->parseLatestServerHistoryInfo($record->latest_server_history_info);
                        app('debugbar')->log($latestInfo);

                        if (! isset($latestInfo['ram_usage'])) {
                            return [
                                'label' => 'RAM',
                                'value' => 0,
                                'tooltip' => 'No data available',
                            ];
                        }

                        // Debug the raw value
                        \Log::info('RAM Free:', ['value' => $latestInfo['ram_usage']]);

                        // Remove any % sign and convert to float
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
                        $latestInfo = $record->parseLatestServerHistoryInfo($record->latest_server_history_info);

                        if (! isset($latestInfo['cpu_usage'])) {
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
                CopyAction::make()
                    ->copyable(fn (Server $record) => ServerInformationHistory::copyCommand($record->id))
                    ->label(__('Copy script')),
                CopyAction::make()
                    ->copyable(fn (Server $record) => ServerLogFileHistory::copyCommand($record->id))
                    ->label(__('Copy log script')),
                Tables\Actions\ViewAction::make('view_statistics')
                    ->label('View statistics')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->color('warning'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
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
                ->action(fn () => $this->post->delete()),
        ];
    }

    public static function getTableActions(): array
    {
        return [
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ];
    }
}
