<?php

    namespace App\Filament\Resources;

    use App\Filament\Resources\ProjectsResource\Pages;
    use App\Filament\Resources\ProjectsResource\RelationManagers;
    use App\Models\Projects;
    use Filament\Forms;
    use Filament\Forms\Form;
    use Filament\Infolists\Components\Actions;
    use Filament\Infolists\Components\Actions\Action;
    use Filament\Infolists\Components\Fieldset;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Infolists\Infolist;
    use Filament\Resources\Resource;
    use Filament\Support\Enums\IconPosition;
    use Filament\Tables;
    use Filament\Tables\Table;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Support\HtmlString;
    use Illuminate\Support\Str;
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

        public static function getEloquentQuery(): Builder
        {
            return parent::getEloquentQuery()->withCount('errorReported');
        }

        public static function infolist( Infolist $infolist ): Infolist
        {
            return $infolist
                ->schema([
                    Fieldset::make("Step 1")
                        ->schema([
                            TextEntry::make('step1')
                                ->label(new HtmlString("Install <b>Flare</b> to your `dependencies` using this command:"))
                                ->html()
                                ->copyable()
                                ->default("composer require checkybot-labs/laravel-ers --no-interaction")
                                ->formatStateUsing(function ( $state ) {
                                    return "<pre class='text-sm'>" . e($state) . "</pre>";
                                })
                                ->tooltip("Click to copy the command to your clipboard."),
                        ])
                        ->visible(fn( $livewire ) => !$livewire->record->error_reported_count > 0),
                    Fieldset::make("Step 2")
                        ->columns(1)
                        ->schema([
                            TextEntry::make('step2')
                                ->label(new HtmlString("<b>Register Flare</b> in the <code>withExceptions</code> closure of your <code>bootstrap/app.php</code> file:"))
                                ->html()
                                ->default('->withExceptions(function (Exceptions $exceptions) {
    \CheckybotLabs\LaravelErs\Facades\Flare::handles($exceptions);
})->create();')
                                ->formatStateUsing(function ( $state ) {
                                    return "<pre id='step2-content' class='text-sm'>" . e($state) . "</pre>";
                                }),
                            TextEntry::make('step2_note')
                                ->label(new HtmlString("<span class='text-danger-600'><b>Note:</b></span><br>
If your project still uses the older Laravel 10 structure (i.e., no <code>withExceptions</code> closure in <code>bootstrap/app.php</code>), you can register Flare inside the <code>register()</code> method of your <code>App\Providers\AppServiceProvider</code> like this:"))
                                ->html()
                                ->default('$this->reportable(function (Throwable $e) {
    \CheckybotLabs\LaravelErs\Facades\Flare::handles($exceptions);
});')
                                ->formatStateUsing(function ( $state ) {
                                    return "<pre id='span2-note-content' class='text-sm'>" . e($state) . "</pre>";
                                }),
                            Actions::make([
                                \App\Filament\Infolists\Actions\CopyAction::make('copy_ai_instruction')
                                    ->label("Copy AI Instruction")
                                    ->tooltip("Click to copy the instruction to your clipboard.")
                                    ->copyable(function () {
                                        return 'Register Flare in the withExceptions closure of your bootstrap/app.php file:

->withExceptions(function (Exceptions $exceptions) {
    \CheckybotLabs\LaravelErs\Facades\Flare::handles($exceptions);
})->create();

Note:
If your project still uses the older Laravel 10 structure (i.e., no withExceptions closure in bootstrap/app.php), you can register Flare inside the register() method of your App\Providers\AppServiceProvider like this:

$this->reportable(function (Throwable $e) {
    \CheckybotLabs\LaravelErs\Facades\Flare::handles($exceptions);
});

Please implement this package according to our Laravel Project version';
                                    }),
                            ]),
                        ])
                        ->visible(fn( $livewire ) => !$livewire->record->error_reported_count > 0),
                    Fieldset::make("Step 3")
                        ->schema([
                            TextEntry::make('token')
                                ->label(new HtmlString("<b>Copy</b> the token/key to your <code>.env</code> file:"))
                                ->html()
                                ->copyable()
                                ->copyableState(fn( $record ) => "CHECKYBOT_KEY=" . $record->token)
                                ->formatStateUsing(function ( $state ) {
                                    return "<pre class='text-sm'>CHECKYBOT_KEY=" . e($state) . "</pre>";
                                })
                                ->tooltip("Click to copy token to your clipboard.")
                                ->hintActions([
                                    Action::make('regenerate_token')
                                        ->label("Regenerate Token")
                                        ->icon('heroicon-o-arrow-path')
                                        ->action(function ( Projects $record, $livewire ) {
                                            $record->token = Str::random(40);
                                            $record->save();
                                        })
                                        ->iconPosition(IconPosition::After)
                                        ->requiresConfirmation()
                                ]),
                        ])->columns(1)
                        ->visible(fn( $livewire ) => !$livewire->record->error_reported_count > 0),
                    Fieldset::make("Step 4")
                        ->schema([
                            TextEntry::make('test')
                                ->label(new HtmlString("<b>Test</b> if you performed the steps correctly by running the following command:"))
                                ->html()
                                ->default("php artisan flare:test")
                                ->formatStateUsing(function ( $state ) {
                                    return "<pre class='text-sm'>" . e($state) . "</pre>";
                                })
                                ->copyable()
                                ->tooltip("Click to copy the command to your clipboard.")

                        ])->columns(1)
                        ->visible(fn( $livewire ) => !$livewire->record->error_reported_count > 0),

                ])
            ;
        }
    }
