<?php

namespace App\Filament\Resources\ProjectComponents\Schemas;

use App\Models\ProjectComponent;
use App\Support\ComponentHeartbeatSetupSnippet;
use App\Support\HealthStatusLabel;
use App\Support\ProjectComponentDeliveryState;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ProjectComponentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Component')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('project.name')
                            ->label('Application'),
                        TextEntry::make('current_status')
                            ->state(fn (ProjectComponent $record): string => $record->derivedCurrentStatus())
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                            ->color(fn (?string $state): string => match ($state) {
                                'healthy' => 'success',
                                'warning' => 'warning',
                                'danger' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('declared_interval')
                            ->label('Interval'),
                        TextEntry::make('summary')
                            ->state(fn (ProjectComponent $record): string => $record->derivedStatusSummary())
                            ->columnSpanFull()
                            ->default('-'),
                    ])->columns(2),
                Section::make('Derived Health')
                    ->schema([
                        TextEntry::make('delivery_state')
                            ->label('Delivery State')
                            ->state(fn (ProjectComponent $record): string => ProjectComponentDeliveryState::label($record))
                            ->badge()
                            ->color(fn (string $state): string => ProjectComponentDeliveryState::color($state)),
                        TextEntry::make('active_child_checks')
                            ->label('Active Child Checks')
                            ->state(fn (ProjectComponent $record): int => $record->activeMonitorApis()->count() + $record->activeWebsites()->count()),
                        TextEntry::make('archived_child_checks')
                            ->label('Disabled / Archived Child Checks')
                            ->state(fn (ProjectComponent $record): int => $record->monitorApis()->withTrashed()->where('is_enabled', false)->count()
                                + $record->websites()->withTrashed()->where('uptime_check', false)->where('ssl_check', false)->count()),
                    ])->columns(2),
                Section::make('Package Setup')
                    ->description('Copy the Laravel package definition for this component.')
                    ->schema([
                        TextEntry::make('package_setup_snippet')
                            ->label('Package Definition')
                            ->state(fn (ProjectComponent $record): string => ComponentHeartbeatSetupSnippet::componentPackageDefinition($record))
                            ->formatStateUsing(fn (string $state): HtmlString => static::codeBlock($state))
                            ->html()
                            ->copyable()
                            ->copyMessage('Package snippet copied')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function codeBlock(string $state): HtmlString
    {
        return new HtmlString(
            '<pre class="overflow-x-auto whitespace-pre-wrap rounded-lg border border-transparent bg-gray-950 p-4 text-sm text-gray-100 dark:border-gray-300 dark:bg-gray-100 dark:text-gray-950">'
            .e($state)
            .'</pre>'
        );
    }
}
