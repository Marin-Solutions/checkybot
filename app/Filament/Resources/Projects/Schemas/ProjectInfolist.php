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
                Section::make('Package Sync Status')
                    ->description('Latest package sync metadata for diagnosing stale or incomplete application integrations.')
                    ->schema([
                        TextEntry::make('last_synced_at')
                            ->label('Last Synced')
                            ->state(fn (Project $record): ?string => $record->last_synced_at?->toDayDateTimeString())
                            ->default('Never')
                            ->hint(fn (Project $record): ?string => $record->last_synced_at?->diffForHumans()),
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
        return $record->packageManagedWebsites()->withTrashed()->count()
            + $record->packageManagedApis()->withTrashed()->count();
    }

    private static function syncedComponentsCount(Project $record): int
    {
        return $record->components()
            ->where('source', 'package')
            ->count();
    }
}
