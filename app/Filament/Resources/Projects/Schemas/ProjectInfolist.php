<?php

namespace App\Filament\Resources\Projects\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('Application')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('environment')
                            ->default('Unknown'),
                        TextEntry::make('technology')
                            ->default('-'),
                        TextEntry::make('group')
                            ->default('-'),
                        TextEntry::make('tracked_components')
                            ->label('Tracked Components')
                            ->state(fn (\App\Models\Project $record): string => $record->components()
                                ->orderBy('name')
                                ->pluck('name')
                                ->implode(', '))
                            ->columnSpanFull()
                            ->default('-'),
                    ])->columns(2),
            ]);
    }
}
