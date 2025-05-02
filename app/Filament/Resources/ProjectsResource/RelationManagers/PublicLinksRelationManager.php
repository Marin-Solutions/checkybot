<?php

    namespace App\Filament\Resources\ProjectsResource\RelationManagers;

    use Filament\Forms;
    use Filament\Forms\Form;
    use Filament\Resources\RelationManagers\RelationManager;
    use Filament\Tables;
    use Filament\Tables\Table;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\Relations\Relation;

    class PublicLinksRelationManager extends RelationManager
    {
        protected static string $relationship = 'publicLinks';

        public function form( Form $form ): Form
        {
            return $form
                ->schema([
                    Forms\Components\TextInput::make('token')
                        ->required()
                        ->maxLength(255),
                ])
            ;
        }

        public function table( Table $table ): Table
        {
            return $table
                ->recordTitleAttribute('token')
                ->columns([
                    Tables\Columns\TextColumn::make('token'),
                ])
                ->filters([
                    //
                ])
                ->headerActions([
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
            ;
        }
    }
