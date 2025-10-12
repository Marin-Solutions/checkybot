<?php

namespace App\Filament\Resources\ServerResource\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class LogCategoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'logCategories';

    protected static ?string $modelLabel = 'Log file Category';

    protected static ?string $title = 'Log File Categories';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('log_directory')
                    ->required()
                    ->maxLength(255),
                Fieldset::make('Setting')
                    ->columnSpanFull()
                    ->schema([
                        Forms\Components\Toggle::make('should_collect')
                            ->required()
                            ->onColor('success')
                            ->default(true),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name'),
                Tables\Columns\TextColumn::make('log_directory'),
                Tables\Columns\ToggleColumn::make('should_collect'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()->authorize(true),
            ])
            ->actions([
                EditAction::make()->authorize(true),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
