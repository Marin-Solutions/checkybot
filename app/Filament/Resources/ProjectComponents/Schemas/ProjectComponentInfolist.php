<?php

namespace App\Filament\Resources\ProjectComponents\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ProjectComponentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('Component')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('project.name')
                            ->label('Application'),
                        TextEntry::make('current_status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'healthy' => 'success',
                                'warning' => 'warning',
                                'danger' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('declared_interval')
                            ->label('Interval'),
                        TextEntry::make('summary')
                            ->columnSpanFull()
                            ->default('-'),
                        TextEntry::make('heartbeat_history')
                            ->label('Heartbeat History')
                            ->state(fn (\App\Models\ProjectComponent $record): string => $record->heartbeats()
                                ->orderByDesc('observed_at')
                                ->limit(5)
                                ->get()
                                ->map(fn (\App\Models\ProjectComponentHeartbeat $heartbeat): string => "{$heartbeat->event}: {$heartbeat->summary}")
                                ->implode(' | '))
                            ->columnSpanFull()
                            ->default('-'),
                    ])->columns(2),
            ]);
    }
}
