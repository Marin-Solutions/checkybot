<?php

    namespace App\Filament\Resources\PloiAccountsResource\RelationManagers;

    use Filament\Forms;
    use Filament\Forms\Form;
    use Filament\Resources\RelationManagers\RelationManager;
    use Filament\Tables;
    use Filament\Tables\Table;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\SoftDeletingScope;

    class ServersRelationManager extends RelationManager
    {
        protected static string $relationship = 'servers';

        protected static ?string $modelLabel = 'Server';

        public function form( Form $form ): Form
        {
            return $form
                ->schema([
                    Forms\Components\TextInput::make('label')
                        ->required()
                        ->maxLength(255),
                ])
            ;
        }

        public function table( Table $table ): Table
        {
            return $table
                ->recordTitleAttribute('label')
                ->columns([
                    Tables\Columns\TextColumn::make('server_id')->label('Id'),
                    Tables\Columns\TextColumn::make('type'),
                    Tables\Columns\TextColumn::make('name'),
                    Tables\Columns\TextColumn::make('ip_address'),
                    Tables\Columns\TextColumn::make('php_version')->sortable(),
                    Tables\Columns\TextColumn::make('mysql_version')->sortable(),
                    Tables\Columns\TextColumn::make('sites_count')->sortable()->numeric(),
                    Tables\Columns\TextColumn::make('status'),
                    Tables\Columns\TextColumn::make('status_id')->sortable(),
                ])
                ->filters([
                    //
                ])
                ->headerActions([
                    Tables\Actions\CreateAction::make(),
                    Tables\Actions\Action::make('import_server')
                        ->label('Import Server')
                        ->action(function () {
                            try {
                                $service  = new \App\Services\PloiServerImportService($this->getOwnerRecord()->key, auth()->id(), $this->getOwnerRecord()->id);
                                $imported = $service->import();

                                \Filament\Notifications\Notification::make()
                                    ->title('Import complete')
                                    ->body("Imported/updated {$imported} servers.")
                                    ->success()
                                    ->send()
                                ;
                            } catch ( \Exception $e ) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Import failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send()
                                ;
                            }
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-cloud-arrow-down'),
                ])
                ->actions([
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])
                ->bulkActions([
                    Tables\Actions\BulkActionGroup::make([
                        Tables\Actions\DeleteBulkAction::make(),
                    ]),
                ])
                ->emptyStateDescription('You can import servers from your Ploi account by clicking the "Import Server" button above.')
            ;
        }
    }
