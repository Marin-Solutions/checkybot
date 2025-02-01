<?php

namespace App\Filament\Resources\MonitorApisResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AssertionsRelationManager extends RelationManager
{
    protected static string $relationship = 'assertions';
    protected static ?string $title = 'API Assertions';
    protected static ?string $recordTitleAttribute = 'data_path';

    protected static ?string $inverseRelationship = 'monitorApi';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('data_path')
                    ->required()
                    ->label('JSON Path')
                    ->helperText('The path to the value in the JSON response (e.g. data.user.id)'),

                Forms\Components\Select::make('assertion_type')
                    ->required()
                    ->options([
                        'type_check' => 'Check Type',
                        'value_compare' => 'Compare Value',
                        'exists' => 'Value Exists',
                        'not_exists' => 'Value Does Not Exist',
                        'array_length' => 'Array Length',
                        'regex_match' => 'Regex Match'
                    ])
                    ->reactive()
                    ->afterStateUpdated(fn($state, Forms\Set $set) => $set('comparison_operator', null)),

                Forms\Components\Select::make('expected_type')
                    ->options([
                        'string' => 'String',
                        'integer' => 'Integer',
                        'boolean' => 'Boolean',
                        'array' => 'Array',
                        'object' => 'Object',
                        'float' => 'Float',
                        'null' => 'Null'
                    ])
                    ->required(fn(Forms\Get $get) => $get('assertion_type') === 'type_check')
                    ->visible(fn(Forms\Get $get) => $get('assertion_type') === 'type_check'),

                Forms\Components\Select::make('comparison_operator')
                    ->options([
                        '=' => 'Equals',
                        '!=' => 'Not Equals',
                        '>' => 'Greater Than',
                        '<' => 'Less Than',
                        '>=' => 'Greater Than or Equal',
                        '<=' => 'Less Than or Equal',
                        'contains' => 'Contains'
                    ])
                    ->required(fn(Forms\Get $get) => in_array($get('assertion_type'), ['value_compare', 'array_length']))
                    ->visible(fn(Forms\Get $get) => in_array($get('assertion_type'), ['value_compare', 'array_length'])),

                Forms\Components\TextInput::make('expected_value')
                    ->required(fn(Forms\Get $get) => in_array($get('assertion_type'), ['value_compare', 'array_length']))
                    ->visible(fn(Forms\Get $get) => in_array($get('assertion_type'), ['value_compare', 'array_length']))
                    ->label(fn(Forms\Get $get) => $get('assertion_type') === 'array_length' ? 'Expected Length' : 'Expected Value'),

                Forms\Components\TextInput::make('regex_pattern')
                    ->required(fn(Forms\Get $get) => $get('assertion_type') === 'regex_match')
                    ->visible(fn(Forms\Get $get) => $get('assertion_type') === 'regex_match')
                    ->helperText('Regular expression pattern (e.g. /^[0-9]+$/)')
                    ->placeholder('/pattern/'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),

                Forms\Components\TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->label('Sort Order')
                    ->helperText('Lower numbers are evaluated first'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('data_path')
                    ->label('JSON Path')
                    ->searchable(),

                Tables\Columns\TextColumn::make('assertion_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn(string $state): string => str_replace('_', ' ', ucfirst($state))),

                Tables\Columns\TextColumn::make('expected_type')
                    ->label('Expected Type')
                    ->visible(fn($record) => $record && $record->assertion_type === 'type_check'),

                Tables\Columns\TextColumn::make('comparison_operator')
                    ->label('Operator')
                    ->visible(fn($record) => $record && in_array($record->assertion_type, ['value_compare', 'array_length'])),

                Tables\Columns\TextColumn::make('expected_value')
                    ->label('Expected Value')
                    ->visible(fn($record) => $record && in_array($record->assertion_type, ['value_compare', 'array_length'])),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Assertion'),
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
