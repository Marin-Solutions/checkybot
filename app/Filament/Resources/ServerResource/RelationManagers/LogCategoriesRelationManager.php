<?php

namespace App\Filament\Resources\ServerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LogCategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'logCategories';

    protected static ?string $modelLabel = 'Log file Category';

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
                Forms\Components\Fieldset::make('Setting')
                    ->schema([
                        Forms\Components\Toggle::make('should_collect')
                            ->required()
                            ->onColor('success')
                            ->default(true)
                    ])
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('log_directory'),
                Tables\Columns\ToggleColumn::make('should_collect')
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->authorize(true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->authorize(true),
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
