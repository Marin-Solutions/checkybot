<?php

namespace App\Filament\Resources\Projects\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->autofocus()
                            ->maxLength(255),
                        TextInput::make('environment')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ]);
    }
}
