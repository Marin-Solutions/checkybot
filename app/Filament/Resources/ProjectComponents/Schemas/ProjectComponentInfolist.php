<?php

namespace App\Filament\Resources\ProjectComponents\Schemas;

use App\Models\ProjectComponent;
use App\Models\ProjectComponentHeartbeat;
use App\Services\ProjectComponentStaleService;
use App\Support\ComponentHeartbeatSetupSnippet;
use App\Support\HealthStatusLabel;
use App\Support\MetricsPayloadFormatter;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
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
                            ->columnSpanFull()
                            ->default('-'),
                    ])->columns(2),
                Section::make('Timing Evidence')
                    ->schema([
                        TextEntry::make('last_heartbeat_at')
                            ->label('Last Heartbeat')
                            ->state(fn (ProjectComponent $record): ?string => $record->last_heartbeat_at?->toDayDateTimeString())
                            ->default('-')
                            ->hint(fn (ProjectComponent $record): ?string => $record->last_heartbeat_at?->diffForHumans()),
                        TextEntry::make('stale_threshold_at')
                            ->label('Stale Threshold')
                            ->state(fn (ProjectComponent $record): ?string => static::staleThresholdAt($record)?->toDayDateTimeString())
                            ->default('-')
                            ->hint(fn (ProjectComponent $record): ?string => static::staleThresholdHint($record)),
                        TextEntry::make('stale_detected_at')
                            ->label('Stale Detected')
                            ->state(fn (ProjectComponent $record): ?string => $record->stale_detected_at?->toDayDateTimeString())
                            ->default('-')
                            ->hint(fn (ProjectComponent $record): ?string => $record->stale_detected_at?->diffForHumans()),
                        TextEntry::make('delivery_state')
                            ->label('Delivery State')
                            ->state(fn (ProjectComponent $record): string => match (true) {
                                $record->is_archived => 'Archived',
                                $record->is_stale => 'Stale',
                                $record->last_heartbeat_at === null => 'Awaiting first heartbeat',
                                default => 'Receiving heartbeats',
                            })
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'Receiving heartbeats' => 'success',
                                'Awaiting first heartbeat' => 'warning',
                                'Stale' => 'danger',
                                default => 'gray',
                            }),
                    ])->columns(2),
                Section::make('Heartbeat Setup')
                    ->description('Copy a Laravel package definition or direct API heartbeat template for this component. Replace the API key before sending direct heartbeats.')
                    ->schema([
                        TextEntry::make('package_setup_snippet')
                            ->label('Package Definition')
                            ->state(fn (ProjectComponent $record): string => ComponentHeartbeatSetupSnippet::componentPackageDefinition($record))
                            ->formatStateUsing(fn (string $state): HtmlString => static::codeBlock($state))
                            ->html()
                            ->copyable()
                            ->copyMessage('Package snippet copied')
                            ->columnSpanFull(),
                        TextEntry::make('direct_api_snippet')
                            ->label('Direct API Heartbeat')
                            ->state(fn (ProjectComponent $record): string => ComponentHeartbeatSetupSnippet::componentCurl($record))
                            ->formatStateUsing(fn (string $state): HtmlString => static::codeBlock($state))
                            ->html()
                            ->copyable()
                            ->copyMessage('API heartbeat snippet copied')
                            ->columnSpanFull(),
                    ]),
                Section::make('Current Metrics')
                    ->hidden(fn (ProjectComponent $record): bool => blank($record->metrics))
                    ->schema([
                        KeyValueEntry::make('metrics')
                            ->label('Latest Payload')
                            ->default([]),
                    ]),
                Section::make('Recent Event Evidence')
                    ->hidden(fn (ProjectComponent $record): bool => ! $record->heartbeats()->exists())
                    ->schema([
                        RepeatableEntry::make('recent_heartbeat_evidence')
                            ->label('')
                            ->state(fn (ProjectComponent $record): array => $record->heartbeats()
                                ->limit(5)
                                ->get()
                                ->map(fn (ProjectComponentHeartbeat $heartbeat): array => [
                                    'event' => $heartbeat->event,
                                    'status' => $heartbeat->status,
                                    'observed_at' => $heartbeat->observed_at?->toDateTimeString(),
                                    'summary' => $heartbeat->summary,
                                    'metrics' => MetricsPayloadFormatter::format($heartbeat->metrics),
                                ])
                                ->all())
                            ->schema([
                                TextEntry::make('event')
                                    ->badge()
                                    ->color(fn (?string $state): string => $state === 'stale' ? 'danger' : 'gray'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'healthy' => 'success',
                                        'warning' => 'warning',
                                        'danger' => 'danger',
                                        default => 'gray',
                                    }),
                                TextEntry::make('observed_at')
                                    ->label('Observed At')
                                    ->default('-'),
                                TextEntry::make('summary')
                                    ->columnSpanFull()
                                    ->default('-'),
                                TextEntry::make('metrics')
                                    ->label('Metrics')
                                    ->html()
                                    ->formatStateUsing(fn (?string $state): HtmlString => new HtmlString('<pre style="white-space: pre-wrap; margin: 0; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, Liberation Mono, Courier New, monospace;">'.e($state ?? '{}').'</pre>'))
                                    ->columnSpanFull(),
                            ])
                            ->contained(false)
                            ->columns(3),
                    ]),
            ]);
    }

    private static function staleThresholdAt(ProjectComponent $record): ?Carbon
    {
        return app(ProjectComponentStaleService::class)->staleThresholdAt($record);
    }

    private static function staleThresholdHint(ProjectComponent $record): ?string
    {
        $staleService = app(ProjectComponentStaleService::class);
        $thresholdAt = $staleService->staleThresholdAt($record);

        if ($thresholdAt === null) {
            return null;
        }

        $thresholdHint = $thresholdAt->lte(now())
            ? 'Expired '.$thresholdAt->diffForHumans()
            : 'Expires '.$thresholdAt->diffForHumans();

        $graceMinutes = $staleService->staleGraceMinutes();

        if ($graceMinutes <= 0) {
            return $thresholdHint;
        }

        return "Includes {$graceMinutes}-minute grace. {$thresholdHint}";
    }

    private static function codeBlock(string $state): HtmlString
    {
        return new HtmlString(
            '<pre class="overflow-x-auto whitespace-pre-wrap rounded-lg bg-gray-950 p-4 text-sm text-gray-100 dark:bg-gray-900 dark:text-gray-100">'
            .e($state)
            .'</pre>'
        );
    }
}
