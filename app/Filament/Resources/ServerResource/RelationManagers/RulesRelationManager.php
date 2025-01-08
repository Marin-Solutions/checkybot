<?php

namespace App\Filament\Resources\ServerResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RulesRelationManager extends RelationManager
{
    protected static string $relationship = 'rules';

    protected static ?string $recordTitleAttribute = 'metric';

    protected static ?string $title = 'Monitoring Rules';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('metric')
                    ->required()
                    ->label('Monitor')
                    ->options([
                        'cpu_usage' => 'CPU Usage',
                        'ram_usage' => 'RAM Usage',
                        'disk_usage' => 'Disk Usage',
                    ])
                    ->columnSpan(2),
                Forms\Components\Grid::make(3)
                    ->schema([
                        Forms\Components\Select::make('operator')
                            ->required()
                            ->options([
                                '>' => 'Above',
                                '<' => 'Below',
                                '=' => 'Equals',
                            ]),
                        Forms\Components\TextInput::make('value')
                            ->required()
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100),
                        Forms\Components\Select::make('channel')
                            ->required()
                            ->options([
                                'email' => 'Email',
                                'slack' => 'Slack',
                                'telegram' => 'Telegram',
                            ]),
                    ]),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->inline(false),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('metric')
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                Tables\Columns\TextColumn::make('operator')
                    ->formatStateUsing(fn (string $state): string => match($state) {
                        '>' => 'Above',
                        '<' => 'Below',
                        '=' => 'Equals',
                    }),
                Tables\Columns\TextColumn::make('value')
                    ->suffix('%'),
                Tables\Columns\TextColumn::make('channel')
                    ->badge()
                    ->color('success'),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('New Monitoring Rule'),
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