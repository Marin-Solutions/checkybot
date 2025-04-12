<?php

    namespace App\Filament\Resources;

    use App\Filament\Resources\ProjectsResource\Pages;
    use App\Filament\Resources\ProjectsResource\RelationManagers;
    use App\Models\Projects;
    use Filament\Forms;
    use Filament\Forms\Form;
    use Filament\Resources\Resource;
    use Filament\Tables;
    use Filament\Tables\Table;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\SoftDeletingScope;
    use Webbingbrasil\FilamentCopyActions\Tables\Actions\CopyAction;

    class ProjectsResource extends Resource
    {
        protected static ?string $model = Projects::class;

        protected static ?string $navigationGroup = 'Operations';
        protected static ?string $navigationIcon = 'heroicon-o-beaker';
        protected static ?int $navigationSort = 6;

        public static function form( Form $form ): Form
        {
            return $form
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Project name')->required(),
                ])
            ;
        }

        public static function table( Table $table ): Table
        {
            return $table
                ->columns([
                    Tables\Columns\TextColumn::make('name')->label("Project name"),
                ])
                ->filters([
                    //
                ])
                ->actions([
                    CopyAction::make()
                        ->copyable(fn( Projects $projects ) => $projects->token)
                        ->label("Copy Token"),
                    Tables\Actions\EditAction::make(),
                ])
                ->bulkActions([
                    Tables\Actions\BulkActionGroup::make([
                        Tables\Actions\DeleteBulkAction::make(),
                    ]),
                ])
            ;
        }

        public static function getRelations(): array
        {
            return [
                //
            ];
        }

        public static function getPages(): array
        {
            return [
                'index'  => Pages\ListProjects::route('/'),
                'create' => Pages\CreateProjects::route('/create'),
                'edit'   => Pages\EditProjects::route('/{record}/edit'),
            ];
        }
    }
