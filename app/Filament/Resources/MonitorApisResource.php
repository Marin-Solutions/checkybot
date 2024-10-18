<?php

    namespace App\Filament\Resources;

    use App\Filament\Resources\MonitorApisResource\Pages;
    use App\Filament\Resources\MonitorApisResource\RelationManagers;
    use App\Models\MonitorApis;
    use Filament\Forms;
    use Filament\Forms\Form;
    use Filament\Resources\Resource;
    use Filament\Tables;
    use Filament\Tables\Table;
    use Illuminate\Database\Eloquent\Builder;
    use Illuminate\Database\Eloquent\SoftDeletingScope;
    use Illuminate\Support\HtmlString;

    class MonitorApisResource extends Resource
    {
        protected static ?string $model = MonitorApis::class;
        protected static ?string $navigationGroup = 'Operations';
        protected static ?int $navigationSort = 3;
        protected static ?string $navigationLabel = 'Monitor APIs';
        protected static ?string $pluralLabel = 'Monitor APIs';

        protected static ?string $navigationIcon = 'heroicon-o-viewfinder-circle';

        public static function form( Form $form ): Form
        {
            return $form
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->columns(2)
                        ->maxLength(155),
                    Forms\Components\TextInput::make('url')
                        ->label('URL')
                        ->required()
                        ->default('https://')
                        ->activeUrl()
                        ->validationMessages([
                            'active_url' => 'The website Url not exists, try again'
                        ])
                        ->url()
                        ->maxLength(255),
                    Forms\Components\Textarea::make('data_path')
                        ->label('Data Path')
                        ->helperText(new HtmlString('Use <b>"dot"</b> notation'))
                        ->columnSpanFull()
                        ->required()
                ])
            ;
        }

        public static function table( Table $table ): Table
        {
            return $table
                ->columns([
                    Tables\Columns\TextColumn::make('title'),
                    Tables\Columns\TextColumn::make('url'),
                    Tables\Columns\TextColumn::make('data_path'),
                ])
                ->filters([
                    //
                ])
                ->actions([
                    Tables\Actions\EditAction::make(),
                ])
                ->bulkActions([
                    Tables\Actions\BulkActionGroup::make([
                        Tables\Actions\DeleteBulkAction::make(),
                    ]),
                ])
                ->emptyStateHeading("No APIs")
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
                'index'  => Pages\ListMonitorApis::route('/'),
                'create' => Pages\CreateMonitorApis::route('/create'),
                'edit'   => Pages\EditMonitorApis::route('/{record}/edit'),
            ];
        }
    }
