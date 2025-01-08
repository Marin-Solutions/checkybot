<?php

namespace App\Filament\Resources\ServerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class LogCategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'logCategories';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Log File Categories';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('log_directory')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Toggle::make('should_collect')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('log_directory'),
                Tables\Columns\ToggleColumn::make('should_collect'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('New Log File Category'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
