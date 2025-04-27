<?php

    namespace App\Filament\Resources\ProjectsResource\RelationManagers;

    use App\Filament\Resources\ProjectsResource;
    use Filament\Forms;
    use Filament\Forms\Form;
    use Filament\Resources\RelationManagers\RelationManager;
    use Filament\Tables;
    use Filament\Tables\Table;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\SoftDeletingScope;

    class ErrorReportedRelationManager extends RelationManager
    {
        protected static string $relationship = 'errorReported';

        protected static ?string $title = "Errors";

        public function form( Form $form ): Form
        {
            return $form
                ->schema([
                    Forms\Components\TextInput::make('exception_class')
                        ->required()
                        ->maxLength(255),
                ])
            ;
        }

        public function table( Table $table ): Table
        {
            return $table
                ->recordTitleAttribute('exception_class')
                ->columns([
                    Tables\Columns\TextColumn::make('exception_class'),
                    Tables\Columns\TextColumn::make('message')->lineClamp(1)->limit(64),
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
                    Tables\Actions\Action::make('view')
                        ->action(function ($record) {
                            $this->redirect(ProjectsResource::getUrl('view-error', ['record' => $this->ownerRecord, 'error' => $record]));
                        })
                ])
                ->bulkActions([
                    Tables\Actions\BulkActionGroup::make([
                        Tables\Actions\DeleteBulkAction::make(),
                    ]),
                ])
            ;
        }
    }
