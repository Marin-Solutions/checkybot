<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MonitorApis\Schemas\MonitorApiInfolist;
use App\Filament\Resources\MonitorApisResource\Pages;
use App\Filament\Resources\MonitorApisResource\RelationManagers;
use App\Filament\Support\HealthStatusFilter;
use App\Models\MonitorApis;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MonitorApisResource extends Resource
{
    protected static ?string $model = MonitorApis::class;

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'API Monitors';

    protected static ?string $modelLabel = 'API Monitor';

    protected static ?string $pluralModelLabel = 'API Monitors';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withAvg('results as avg_response_time', 'response_time_ms')
            ->with(['latestResult'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Monitor Details')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_enabled')
                            ->label('Enabled')
                            ->helperText('Disable this monitor to keep its configuration without running scheduled checks.')
                            ->default(true)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('url')
                            ->required()
                            ->url()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
                Section::make('Request Settings')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('http_method')
                            ->label('HTTP Method')
                            ->options([
                                'GET' => 'GET',
                                'POST' => 'POST',
                                'PUT' => 'PUT',
                                'PATCH' => 'PATCH',
                                'DELETE' => 'DELETE',
                                'HEAD' => 'HEAD',
                                'OPTIONS' => 'OPTIONS',
                            ])
                            ->default('GET')
                            ->required(),
                        Forms\Components\TextInput::make('expected_status')
                            ->label('Expected Status Code')
                            ->numeric()
                            ->default(200)
                            ->minValue(100)
                            ->maxValue(599)
                            ->required()
                            ->helperText('The response status code this monitor should treat as healthy.'),
                        Forms\Components\TextInput::make('timeout_seconds')
                            ->label('Timeout (seconds)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->placeholder((string) config('monitor.api_timeout', 10))
                            ->helperText('Optional override for slow endpoints. Leave blank to use the default timeout.'),
                        Forms\Components\TextInput::make('data_path')
                            ->helperText('Optional JSON path to validate in the response body (for example: data.items).')
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\KeyValue::make('headers')
                            ->keyLabel('Header')
                            ->valueLabel('Value')
                            ->keyPlaceholder('Header Name')
                            ->valuePlaceholder('Header Value')
                            ->helperText('Optional headers to include in the request')
                            ->columnSpanFull()
                            ->addActionLabel('Add Header'),
                    ]),
                Section::make('Failure Handling')
                    ->schema([
                        Forms\Components\Toggle::make('save_failed_response')
                            ->label('Save Response Body on Failure')
                            ->helperText('When enabled, the full response body will be saved when assertions fail')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('url')
                    ->searchable(),
                Tables\Columns\TextColumn::make('current_status')
                    ->label('Health')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_enabled')
                    ->label('Enabled')
                    ->boolean(),
                Tables\Columns\TextColumn::make('data_path')
                    ->searchable(),
                Tables\Columns\TextColumn::make('avg_response_time')
                    ->label('Avg Response Time (ms)')
                    ->default('-')
                    ->formatStateUsing(fn ($state) => $state === '-' ? '-' : round($state))
                    ->sortable(),
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
                HealthStatusFilter::make(),
                HealthStatusFilter::onlyFailing(),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('test')
                    ->label('Test API')
                    ->color('warning')
                    ->icon('heroicon-o-play')
                    ->action(function (MonitorApis $record) {
                        MonitorApis::testApi([
                            'id' => $record->id,
                            'url' => $record->url,
                            'method' => $record->http_method,
                            'data_path' => $record->data_path,
                            'headers' => $record->headers,
                            'expected_status' => $record->expected_status,
                            'timeout_seconds' => $record->timeout_seconds,
                            'title' => $record->title,
                        ]);
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No APIs');
    }

    public static function infolist(Schema $schema): Schema
    {
        return MonitorApiInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AssertionsRelationManager::class,
            RelationManagers\ResultsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMonitorApis::route('/'),
            'create' => Pages\CreateMonitorApis::route('/create'),
            'view' => Pages\ViewMonitorApis::route('/{record}'),
            'edit' => Pages\EditMonitorApis::route('/{record}/edit'),
        ];
    }
}
