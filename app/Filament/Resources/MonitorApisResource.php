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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(150),
                Forms\Components\TextInput::make('url')
                    ->required()
                    ->url()
                    ->unique(ignoreRecord: true),
                Forms\Components\TextInput::make('data_path')
                    ->required()
                    ->helperText('The path to the value in the JSON response (e.g. data.user.id)'),
            ]);
    }

    public static function table(Table $table): Table
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
                Tables\Actions\Action::make('test')
                    ->label('Test API')
                    ->color('warning')
                    ->icon('heroicon-o-play')
                    ->action(function (MonitorApis $record) {
                        $record->testApi(['id' => $record->id, 'url' => $record->url]);
                    }),
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
            RelationManagers\AssertionsRelationManager::class,
            RelationManagers\ResultsRelationManager::class,
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
