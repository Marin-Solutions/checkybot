<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Application')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('application_status')
                            ->label('Current Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'healthy' => 'success',
                                'warning' => 'warning',
                                'danger' => 'danger',
                                default => 'gray',
                            }),
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
                Section::make('Guided Laravel Setup')
                    ->schema([
                        TextEntry::make('guided_setup_snippet')
                            ->label('Install Snippet')
                            ->state(fn (Project $record): string => $record->guidedSetupSnippet())
                            ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(
                                '<pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-4 text-sm text-white">'
                                .e($state)
                                .'</pre>'
                            ))
                            ->html()
                            ->copyable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
