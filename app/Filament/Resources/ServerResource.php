<?php

namespace App\Filament\Resources;

use App\Models\ServerLogFileHistory;
use Filament\Forms;
use Filament\Tables;
use App\Models\Server;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Illuminate\Database\Eloquent\Builder;
use App\Filament\Resources\ServerResource\Pages;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\ServerResource\RelationManagers;
use App\Models\ServerInformationHistory;
use Webbingbrasil\FilamentCopyActions\Tables\Actions\CopyAction;
use App\Tables\Columns\UsageBarColumn;

class ServerResource extends Resource
{
    protected static ?string $model = Server::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        return number_format(static::getModel()::count());
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
                    ->maxLength(255)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('ip')
                    ->label('IP')
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
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
                        $latestInfo = $record->informationHistory()
                            ->orderBy('id', 'desc')
                            ->first();

                        // Debug output
                        \Log::info('Disk Usage Debug', [
                            'server_id' => $record->id,
                            'has_latest_info' => $latestInfo ? 'yes' : 'no',
                            'raw_data' => $latestInfo?->toArray()
                        ]);

                        if (!$latestInfo) {
                            return [
                                'value' => 0,
                                'tooltip' => "No data available"
                            ];
                        }

                        $freePercentage = (float) str_replace(['%', ' '], '', $latestInfo->disk_free_percentage);
                        $usedPercentage = 100 - $freePercentage;

                        return [
                            'value' => $usedPercentage,
                            'tooltip' => sprintf("Used: %.1f%%\nFree: %.1f%%", $usedPercentage, $freePercentage)
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
                        $latestInfo = $record->informationHistory()
                            ->orderBy('id', 'desc')
                            ->first();

                        if (!$latestInfo) {
                            return [
                                'label' => 'RAM',
                                'value' => 0,
                                'tooltip' => "No data available"
                            ];
                        }

                        // Debug the raw value
                        \Log::info('RAM Free:', ['value' => $latestInfo->ram_free_percentage]);

                        // Remove any % sign and convert to float
                        $freePercentage = (float) str_replace(['%', ' '], '', $latestInfo->ram_free_percentage);
                        $usedPercentage = 100 - $freePercentage;

                        return [
                            'label' => 'RAM',
                            'value' => $usedPercentage,
                            'tooltip' => sprintf("Used: %.1f%%\nFree: %.1f%%", $usedPercentage, $freePercentage)
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
                        $latestInfo = $record->informationHistory()
                            ->orderBy('id', 'desc')
                            ->first();

                        if (!$latestInfo) {
                            return [
                                'value' => 0,
                                'tooltip' => "No data available"
                            ];
                        }

                        // Get CPU usage directly from CPU_LOAD
                        $cpuUsage = (float) str_replace(',', '.', $latestInfo->cpu_load);

                        return [
                            'value' => min(100, $cpuUsage), // Cap at 100%
                            'tooltip' => sprintf(
                                "CPU Load: %.1f%%\nCores: %d",
                                $cpuUsage,
                                $record->cpu_cores ?? 0
                            )
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
                //Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                CopyAction::make()
                    ->copyable(fn(Server $record) => ServerInformationHistory::copyCommand($record->id))
                    ->label(__('Copy script')),
                CopyAction::make()
                    ->copyable(fn(Server $record) => ServerLogFileHistory::copyCommand($record->id))
                    ->label(__('Copy log script')),
                Tables\Actions\ViewAction::make('view_statistics')
                    ->label('View statistics')
                    ->icon('heroicon-o-presentation-chart-line')
                    ->color('warning')
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
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ];
    }
}
