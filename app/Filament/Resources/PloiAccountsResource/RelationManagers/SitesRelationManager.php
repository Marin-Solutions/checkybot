<?php

    namespace App\Filament\Resources\PloiAccountsResource\RelationManagers;

    use Filament\Forms;
    use Filament\Forms\Form;
    use Filament\Resources\RelationManagers\RelationManager;
    use Filament\Tables;
    use Filament\Tables\Table;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\SoftDeletingScope;

    class SitesRelationManager extends RelationManager
    {
        protected static string $relationship = 'sites';

        public function form( Form $form ): Form
        {
            return $form
                ->schema([
                    Forms\Components\TextInput::make('domain')
                        ->required()
                        ->maxLength(255),
                ])
            ;
        }

        public function table( Table $table ): Table
        {
            return $table
                ->recordTitleAttribute('domain')
                ->columns([
                    Tables\Columns\TextColumn::make('domain')
                        ->searchable()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('server.name')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('php_version')
                        ->label('PHP Version')
                        ->sortable(),
                    Tables\Columns\TextColumn::make('status')
                        ->badge()
                        ->sortable(),
                    Tables\Columns\TextColumn::make('site_created_at')
                        ->dateTime()
                        ->sortable()
                        ->toggleable(),
                ])
                ->filters([
                    //
                ])
                ->headerActions([
                    Tables\Actions\Action::make('import_all_site')
                        ->label('Import All Sites')
                        ->action(function () {
                            if ( $this->getOwnerRecord()->servers()->count() === 0 ) {
                                \Filament\Notifications\Notification::make()
                                    ->title('No Servers Available')
                                    ->body('You need to import servers first before importing sites. You can do this by clicking the "Import Server" button.')
                                    ->warning()
                                    ->persistent()
                                    ->send()
                                ;
                            } else {
                                try {
                                    $service  = new \App\Services\PloiSiteImportService($this->getOwnerRecord());
                                    $imported = $service->import();

                                    \Filament\Notifications\Notification::make()
                                        ->title('Import complete')
                                        ->body("Imported/updated {$imported} sites.")
                                        ->success()
                                        ->persistent()
                                        ->send()
                                    ;
                                } catch ( \Exception $e ) {
                                    \Filament\Notifications\Notification::make()
                                        ->title('Import failed')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->persistent()
                                        ->send()
                                    ;
                                }
                            }
                        })
                        ->requiresConfirmation()
                        ->icon('heroicon-o-globe-alt'),
                    Tables\Actions\CreateAction::make(),
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
                ->emptyStateDescription(
                    'No sites found for this account. You can import existing sites from Ploi.'
                )
            ;
        }
    }
