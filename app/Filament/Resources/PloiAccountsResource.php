<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PloiAccountsResource\Pages;
use App\Filament\Resources\PloiAccountsResource\RelationManagers;
use App\Models\PloiAccounts;
use App\Traits\HandlesPloiVerificationNotification;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class PloiAccountsResource extends Resource
{
    use HandlesPloiVerificationNotification;

    protected static ?string $model = PloiAccounts::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Ploi Account Information')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\TextInput::make('label')->required()
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('key')
                            ->label('Ploi API Key')
                            ->required()
                            ->columnSpanFull()
                            ->helperText('This is the API key used to connect to Ploi.')
                            ->rows(12),
                    ])
                    ->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label'),
                Tables\Columns\TextColumn::make('label'),
                Tables\Columns\IconColumn::make('is_verified')
                    ->label('Verified')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->tooltip(fn(PloiAccounts $record) => $record->error_message ?: 'No error message'),
                Tables\Columns\TextColumn::make('servers_count')
                    ->label('Servers')
                    ->counts('servers')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                EditAction::make(),
                Action::make('Verify')
                    ->action(function (PloiAccounts $record) {
                        $service = new \App\Services\PloiApiService;
                        $result = $service->verifyKey($record->key);
                        $record->update($result);
                        static::notifyPloiVerificationResult($result);
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->disabled(fn(PloiAccounts $record) => $record->is_verified),
                Action::make('import_servers')
                    ->action(function (PloiAccounts $record) {
                        try {
                            $service = new \App\Services\PloiServerImportService($record->key, auth()->id(), $record->id);
                            $imported = $service->import();

                            \Filament\Notifications\Notification::make()
                                ->title('Import complete')
                                ->body("Imported/updated {$imported} servers.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Import failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->icon('heroicon-o-server')
                    ->requiresConfirmation()
                    ->color('primary'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->recordUrl(
                fn(PloiAccounts $record) => static::getUrl('view', ['record' => $record->getKey()])
            );
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ServersRelationManager::class,
            RelationManagers\SitesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPloiAccounts::route('/'),
            'create' => Pages\CreatePloiAccounts::route('/create'),
            'edit' => Pages\EditPloiAccounts::route('/{record}/edit'),
            'view' => Pages\ViewPloiAccounts::route('/{record}'),
        ];
    }
}
