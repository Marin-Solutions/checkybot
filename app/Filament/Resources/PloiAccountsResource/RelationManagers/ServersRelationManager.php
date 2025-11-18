<?php

namespace App\Filament\Resources\PloiAccountsResource\RelationManagers;

use App\Models\Server;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ServersRelationManager extends RelationManager
{
    protected static string $relationship = 'servers';

    protected static ?string $modelLabel = 'Server';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                \Filament\Forms\Components\TextInput::make('label')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                Tables\Columns\TextColumn::make('server_id')->label('Id')->searchable(),
                Tables\Columns\TextColumn::make('type'),
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('ip_address'),
                Tables\Columns\TextColumn::make('php_version')->sortable(),
                Tables\Columns\TextColumn::make('mysql_version')->sortable(),
                Tables\Columns\TextColumn::make('sites_count')->sortable()->numeric(),
                Tables\Columns\TextColumn::make('status'),
                Tables\Columns\TextColumn::make('status_id')->sortable(),
                Tables\Columns\IconColumn::make('checkybot_server_exists')->exists('checkybotServer')
                    ->label('Added to Checkybot')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make(),
                \Filament\Actions\Action::make('import_all_site')
                    ->label('Import All Sites')
                    ->action(function () {
                        if ($this->getOwnerRecord()->servers()->count() === 0) {
                            \Filament\Notifications\Notification::make()
                                ->title('No Servers Available')
                                ->body('You need to import servers first before importing sites. You can do this by clicking the "Import Server" button.')
                                ->warning()
                                ->persistent()
                                ->send();
                        } else {
                            try {
                                $service = new \App\Services\PloiSiteImportService($this->getOwnerRecord());
                                $imported = $service->import();

                                \Filament\Notifications\Notification::make()
                                    ->title('Import complete')
                                    ->body("Imported/updated {$imported} sites.")
                                    ->success()
                                    ->persistent()
                                    ->send();
                            } catch (\Exception $e) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Import failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        }
                    })
                    ->requiresConfirmation()
                    ->icon('heroicon-o-globe-alt'),
                \Filament\Actions\Action::make('import_server')
                    ->label('Import Server')
                    ->action(function () {
                        try {
                            $service = new \App\Services\PloiServerImportService($this->getOwnerRecord()->key, auth()->id(), $this->getOwnerRecord()->id);
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
                    ->requiresConfirmation()
                    ->icon('heroicon-o-cloud-arrow-down'),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
                \Filament\Actions\Action::make('import_site')
                    ->label('Import Sites')
                    ->action(function ($record) {
                        try {
                            $service = new \App\Services\PloiSiteImportService($this->getOwnerRecord());
                            $imported = $service->import($record);

                            \Filament\Notifications\Notification::make()
                                ->title('Import complete')
                                ->body("Imported/updated {$imported} sites for server {$record->name}.")
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
                    ->requiresConfirmation()
                    ->icon('heroicon-o-cloud-arrow-down'),
                \Filament\Actions\Action::make('add_to_checkybot')
                    ->label('Add to Checkybot')
                    ->requiresConfirmation()
                    ->icon('heroicon-o-presentation-chart-line')
                    ->action(function (Model $record) {
                        try {
                            Server::create([
                                'ip' => $record->ip_address,
                                'name' => $record->name,
                                'description' => "Imported from Ploi server: {$record->id}",
                                'created_by' => auth()->id(),
                                'token' => \Illuminate\Support\Str::random(40),
                                'ploi_server_id' => $record->id,
                            ]);

                            \Filament\Notifications\Notification::make()
                                ->title('Server added to Checkybot')
                                ->body("Server {$record->name} has been added to Checkybot.")
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Failed to add server')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->disabled(
                        fn (Model $record) => $record->checkybot_server_exists
                    ),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateDescription('You can import servers from your Ploi account by clicking the "Import Server" button above.');
    }
}
