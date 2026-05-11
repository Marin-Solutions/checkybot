<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Filament\Resources\ApiKeyResource;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
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
                Section::make('Setup Verification')
                    ->description('Shows whether the guided Laravel setup has registered successfully and delivered its first package sync yet.')
                    ->schema([
                        View::make('filament.resources.projects.setup-verification-panel')
                            ->key('setup_verification_panel')
                            ->viewData(fn (Project $record): array => [
                                'label' => $record->setupVerificationLabel(),
                                'tone' => $record->setupVerificationTone(),
                                'summary' => $record->setupVerificationSummary(),
                                'action' => $record->setupVerificationAction(),
                                'steps' => $record->setupVerificationSteps(),
                            ])
                            ->columnSpanFull(),
                    ]),
                Section::make('Package Sync Status')
                    ->description('Latest package and component sync metadata for diagnosing stale or incomplete application integrations.')
                    ->visible(fn (Project $record): bool => filled($record->package_key)
                        || filled($record->package_version)
                        || $record->last_component_synced_at !== null)
                    ->schema([
                        TextEntry::make('last_synced_at')
                            ->label('Last Synced')
                            ->state(fn (Project $record): ?string => $record->last_synced_at?->toDayDateTimeString())
                            ->default('Never')
                            ->hint(fn (Project $record): ?string => $record->last_synced_at?->diffForHumans()),
                        TextEntry::make('last_component_synced_at')
                            ->label('Last Component Sync')
                            ->state(fn (Project $record): ?string => $record->last_component_synced_at?->toDayDateTimeString())
                            ->default('Never')
                            ->hint(fn (Project $record): ?string => $record->last_component_synced_at?->diffForHumans()),
                        TextEntry::make('package_version')
                            ->label('SDK Version')
                            ->default('-')
                            ->copyable(),
                        TextEntry::make('package_key')
                            ->label('Package Key')
                            ->default('-')
                            ->copyable(),
                        TextEntry::make('base_url')
                            ->label('Base URL')
                            ->default('-')
                            ->copyable(),
                        TextEntry::make('repository')
                            ->default('-')
                            ->copyable(),
                        TextEntry::make('synced_checks_count')
                            ->label('Synced Checks')
                            ->state(fn (Project $record): int => static::syncedChecksCount($record)),
                        TextEntry::make('synced_components_count')
                            ->label('Synced Components')
                            ->state(fn (Project $record): int => static::syncedComponentsCount($record)),
                        TextEntry::make('latest_package_sync_summary_overview')
                            ->label('Last Sync Changes')
                            ->state(fn (Project $record): string => static::latestSyncSummaryOverview($record))
                            ->default('No summary recorded yet'),
                        TextEntry::make('latest_package_sync_summary_detail')
                            ->label('Last Sync Breakdown')
                            ->state(fn (Project $record): string => static::latestSyncSummaryDetail($record))
                            ->default('No summary recorded yet')
                            ->columnSpanFull(),
                        TextEntry::make('latest_component_sync_summary_overview')
                            ->label('Last Component Sync Changes')
                            ->state(fn (Project $record): string => static::latestComponentSyncSummaryOverview($record))
                            ->default('No summary recorded yet'),
                        TextEntry::make('latest_component_sync_summary_detail')
                            ->label('Last Component Sync Breakdown')
                            ->state(fn (Project $record): string => static::latestComponentSyncSummaryDetail($record))
                            ->default('No summary recorded yet'),
                    ])->columns(2),
                Section::make('Guided Laravel Setup')
                    ->key('guided_setup')
                    ->description('Create an account API key here and copy a ready-to-run install snippet without leaving the application page.')
                    ->headerActions([
                        Action::make('createApiKey')
                            ->label('Create API Key')
                            ->icon('heroicon-o-key')
                            ->authorize(fn (): bool => ApiKeyResource::canManageApiKeys())
                            ->schema(ApiKeyResource::getFormSchema())
                            ->fillForm(fn (Project $record): array => [
                                'name' => "{$record->name} setup key",
                            ])
                            ->action(function (array $data, ViewProject $livewire): void {
                                $apiKey = $livewire->issueGuidedSetupApiKey($data);

                                Notification::make()
                                    ->success()
                                    ->title('API key created')
                                    ->body("{$apiKey->name} is ready. The setup snippet below now includes this key for the current session.")
                                    ->send();
                            }),
                        Action::make('manageApiKeys')
                            ->label('Manage API Keys')
                            ->authorize(fn (): bool => ApiKeyResource::canManageApiKeys())
                            ->icon('heroicon-o-cog-6-tooth')
                            ->color('gray')
                            ->url(fn (): string => ApiKeyResource::getUrl('index')),
                    ])
                    ->schema([
                        View::make('filament.resources.projects.guided-setup-api-key-panel')
                            ->key('guided_setup_api_key_panel')
                            ->visible(fn (ViewProject $livewire): bool => filled($livewire->guidedSetupApiKey))
                            ->viewData(fn (ViewProject $livewire): array => [
                                'plainTextKey' => $livewire->guidedSetupApiKey,
                                'keyName' => $livewire->guidedSetupApiKeyName,
                                'snippet' => $livewire->guidedSetupSnippet,
                            ])
                            ->columnSpanFull(),
                        TextEntry::make('guided_setup_snippet')
                            ->key('guided_setup_snippet')
                            ->label('Install Snippet')
                            ->state(fn (ViewProject $livewire): string => $livewire->guidedSetupSnippet)
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

    private static function syncedChecksCount(Project $record): int
    {
        return static::syncedWebsiteChecksCount($record)
            + $record->packageManagedApis()->withTrashed()->count();
    }

    private static function syncedWebsiteChecksCount(Project $record): int
    {
        return (int) $record->packageManagedWebsites()
            ->withTrashed()
            ->selectRaw(<<<'SQL'
                COALESCE(SUM(CASE WHEN uptime_check THEN 1 ELSE 0 END), 0)
                + COALESCE(SUM(CASE WHEN ssl_check THEN 1 ELSE 0 END), 0)
                + COALESCE(SUM(CASE WHEN NOT uptime_check AND NOT ssl_check THEN 1 ELSE 0 END), 0) as total
            SQL)
            ->value('total');
    }

    private static function syncedComponentsCount(Project $record): int
    {
        return $record->components()
            ->where('source', 'package')
            ->count();
    }

    private static function latestSyncSummaryOverview(Project $record): string
    {
        $summary = $record->latest_package_sync_summary;

        if (! is_array($summary) || $summary === []) {
            return 'No summary recorded yet';
        }

        return collect([
            static::summaryPart('created', static::summaryTotal($summary, 'created')),
            static::summaryPart('updated', static::summaryTotal($summary, 'updated')),
            static::summaryPart('disabled', static::summaryDisabledCount($summary)),
        ])
            ->filter()
            ->implode(', ') ?: 'No check changes';
    }

    private static function latestSyncSummaryDetail(Project $record): string
    {
        $summary = $record->latest_package_sync_summary;

        if (! is_array($summary) || $summary === []) {
            return 'No summary recorded yet';
        }

        return collect([
            static::summaryLine($summary, 'api_checks', 'API checks'),
            static::summaryLine($summary, 'uptime_checks', 'Uptime checks'),
            static::summaryLine($summary, 'ssl_checks', 'SSL checks'),
        ])
            ->filter()
            ->implode(PHP_EOL) ?: 'No per-check breakdown recorded';
    }

    private static function latestComponentSyncSummaryOverview(Project $record): string
    {
        $summary = $record->latest_component_sync_summary;

        if (! is_array($summary) || $summary === []) {
            return 'No summary recorded yet';
        }

        $recordedHeartbeats = static::summaryNestedCount($summary, 'heartbeats', 'recorded');

        return collect([
            static::summaryPart('created', static::summaryNestedCount($summary, 'components', 'created')),
            static::summaryPart('updated', static::summaryNestedCount($summary, 'components', 'updated')),
            static::summaryPart('archived', static::summaryNestedCount($summary, 'components', 'archived')),
            static::summaryPart($recordedHeartbeats === 1 ? 'heartbeat recorded' : 'heartbeats recorded', $recordedHeartbeats),
        ])
            ->filter()
            ->implode(', ') ?: 'No component changes';
    }

    private static function latestComponentSyncSummaryDetail(Project $record): string
    {
        $summary = $record->latest_component_sync_summary;

        if (! is_array($summary) || $summary === []) {
            return 'No summary recorded yet';
        }

        $componentParts = collect([
            static::summaryPart('created', static::summaryNestedCount($summary, 'components', 'created')),
            static::summaryPart('updated', static::summaryNestedCount($summary, 'components', 'updated')),
            static::summaryPart('archived', static::summaryNestedCount($summary, 'components', 'archived')),
        ])
            ->filter()
            ->implode(', ');

        $heartbeatParts = collect([
            static::summaryPart('recorded', static::summaryNestedCount($summary, 'heartbeats', 'recorded')),
        ])
            ->filter()
            ->implode(', ');

        return collect([
            'Components: '.($componentParts === '' ? 'no changes' : $componentParts),
            'Heartbeats: '.($heartbeatParts === '' ? 'none recorded' : $heartbeatParts),
        ])->implode(PHP_EOL);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function summaryLine(array $summary, string $key, string $label): ?string
    {
        if (! isset($summary[$key]) || ! is_array($summary[$key])) {
            return null;
        }

        $counts = $summary[$key];
        $parts = collect([
            static::summaryPart('created', static::summaryCount($counts, 'created')),
            static::summaryPart('updated', static::summaryCount($counts, 'updated')),
            static::summaryPart('disabled', static::summaryDisabledCount($counts)),
        ])
            ->filter()
            ->implode(', ');

        return $label.': '.($parts === '' ? 'no changes' : $parts);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function summaryPart(string $label, int $count): ?string
    {
        return $count > 0 ? "{$count} {$label}" : null;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function summaryCount(array $summary, string $key): int
    {
        return (int) ($summary[$key] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function summaryDisabledCount(array $summary): int
    {
        return static::summaryTotal($summary, 'disabled_missing')
            + static::summaryTotal($summary, 'deleted');
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function summaryNestedCount(array $summary, string $bucket, string $key): int
    {
        if (! isset($summary[$bucket]) || ! is_array($summary[$bucket])) {
            return 0;
        }

        return static::summaryCount($summary[$bucket], $key);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private static function summaryTotal(array $summary, string $key): int
    {
        if (array_key_exists($key, $summary)) {
            return static::summaryCount($summary, $key);
        }

        return collect(['api_checks', 'uptime_checks', 'ssl_checks'])
            ->sum(fn (string $bucket): int => isset($summary[$bucket]) && is_array($summary[$bucket])
                ? static::summaryCount($summary[$bucket], $key)
                : 0);
    }
}
