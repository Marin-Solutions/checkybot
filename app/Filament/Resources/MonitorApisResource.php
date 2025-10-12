<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MonitorApisResource\Pages;
use App\Filament\Resources\MonitorApisResource\RelationManagers;
use App\Models\MonitorApis;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class MonitorApisResource extends Resource
{
    protected static ?string $model = MonitorApis::class;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'API Monitors';

    protected static ?string $modelLabel = 'API Monitor';

    protected static ?string $pluralModelLabel = 'API Monitors';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withAvg('results as avg_response_time', 'response_time_ms');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('API Monitor Information')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('url')
                            ->required()
                            ->url()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('data_path')
                            ->helperText('The path to the data in the JSON response (e.g. "data.items")')
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
                    ])
                    ->columns(1),
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
                Tables\Columns\TextColumn::make('data_path')
                    ->searchable(),
                Tables\Columns\TextColumn::make('avg_response_time')
                    ->label('Avg Response Time (ms)')
                    ->default('-')
                    ->formatStateUsing(fn($state) => $state === '-' ? '-' : round($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('test')
                    ->label('Test API')
                    ->color('warning')
                    ->icon('heroicon-o-play')
                    ->action(function (MonitorApis $record) {
                        $record->testApi(['id' => $record->id, 'url' => $record->url]);
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No APIs');
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
