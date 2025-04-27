<?php

    namespace App\Filament\Resources;

    use App\Filament\Resources\ProjectsResource\Pages;
    use App\Filament\Resources\ProjectsResource\RelationManagers;
    use App\Models\Projects;
    use Filament\Forms;
    use Filament\Forms\Form;
    use Filament\Infolists\Components\Fieldset;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Infolists\Infolist;
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
                    Tables\Columns\TextColumn::make("error_reported_count")->counts('errorReported')->label("Errors")
                ])
                ->filters([
                    //
                ])
                ->actions([
                    Tables\Actions\ViewAction::make(),
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
                RelationManagers\ErrorReportedRelationManager::make()
            ];
        }

        public static function getPages(): array
        {
            return [
                'index'      => Pages\ListProjects::route('/'),
                'create'     => Pages\CreateProjects::route('/create'),
                'view'       => Pages\ViewProjects::route('/{record}'),
                'edit'       => Pages\EditProjects::route('/{record}/edit'),
                'view-error' => Pages\ViewProjectsError::route('{record}/error/{error}')
            ];
        }

        public static function infolist( Infolist $infolist ): Infolist
        {
            return $infolist
                ->schema([
                    Fieldset::make('Name')
                        ->schema([
                            TextEntry::make('name')->label(''),
                        ])
                ])
            ;
        }
    }
