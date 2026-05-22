<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Support\MonitorSnoozeAction;
use App\Filament\Support\HealthStatusFilter;
use App\Jobs\RunApiMonitorDiagnosticJob;
use App\Models\MonitorApis;
use App\Models\ProjectComponent;
use App\Support\HealthStatusLabel;
use App\Support\ScheduledFailureStreak;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PackageManagedApisRelationManager extends RelationManager
{
    protected static string $relationship = 'packageManagedApis';

    protected static ?string $title = 'Package-managed APIs';

    private const UNMAPPED_COMPONENT_FILTER = '__unmapped';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('url')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('current_status')
                    ->label('Health')
                    ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                    ->badge()
                    ->color(fn (?string $state): string => HealthStatusLabel::color($state)),
                TextColumn::make('latestDiagnosticResult.status')
                    ->label('Manual')
                    ->state(fn (MonitorApis $record): ?string => $record->hasQueuedDiagnostic()
                        ? 'Queued'
                        : $record->latestDiagnosticResult?->status)
                    ->formatStateUsing(fn (?string $state): string => $state === 'Queued'
                        ? $state
                        : HealthStatusLabel::format($state))
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'Queued'
                        ? 'warning'
                        : HealthStatusLabel::color($state))
                    ->icon(fn (MonitorApis $record): ?string => $this->manualRunDrifts($record)
                        ? 'heroicon-o-exclamation-triangle'
                        : null)
                    ->description(fn (MonitorApis $record): ?string => $this->manualRunDescription($record))
                    ->placeholder('—'),
                TextColumn::make('deleted_at')
                    ->label('State')
                    ->state(fn (MonitorApis $record): string => $this->monitoringState($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Disabled' => 'warning',
                        'Archived' => 'gray',
                        default => 'gray',
                    })
                    ->description(fn (MonitorApis $record): ?string => $this->monitoringStateDescription($record)),
                TextColumn::make('status_summary')
                    ->label('Summary')
                    ->wrap()
                    ->limit(90)
                    ->default('-'),
                TextColumn::make('scheduled_failure_streak')
                    ->label('Failure Streak')
                    ->state(fn (MonitorApis $record): ?string => ScheduledFailureStreak::displayForApi($record))
                    ->placeholder('-')
                    ->color('danger')
                    ->wrap(),
                TextColumn::make('latestResult.created_at')
                    ->label('Last Scheduled Check')
                    ->state(fn (MonitorApis $record): ?string => $record->latestResult?->created_at?->toDayDateTimeString())
                    ->description(fn (MonitorApis $record): ?string => $record->latestResult?->created_at?->diffForHumans())
                    ->default('-'),
                TextColumn::make('silenced_until')
                    ->label('Snoozed')
                    ->badge()
                    ->color('warning')
                    ->icon('heroicon-o-bell-slash')
                    ->state(fn (MonitorApis $record): ?string => $record->isSilenced()
                        ? 'Until '.$record->silenced_until->format('M j, H:i')
                        : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('package_interval')
                    ->label('Interval'),
            ])
            ->filters([
                HealthStatusFilter::make()
                    ->label('Health'),
                SelectFilter::make('monitoring_state')
                    ->label('State')
                    ->options([
                        'active' => 'Active',
                        'disabled' => 'Disabled',
                        'archived' => 'Archived',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $this->applyMonitoringStateFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
                SelectFilter::make('project_component_id')
                    ->label('Component')
                    ->options(fn (): array => $this->componentFilterOptions())
                    ->query(fn (Builder $query, array $data): Builder => $this->applyComponentFilter(
                        $query,
                        $data['value'] ?? null,
                    ))
                    ->searchable()
                    ->preload(),
                SelectFilter::make('manual_run_status')
                    ->label('Manual run')
                    ->options([
                        'queued' => 'Queued',
                        'drift' => 'Differs from live health',
                        'matching' => 'Matches live health',
                        'missing' => 'No manual run',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $this->applyManualRunStatusFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
            ])
            ->recordActions([
                Action::make('snooze')
                    ->label(fn (MonitorApis $record): string => $record->isSilenced() ? 'Snoozed' : 'Snooze')
                    ->icon('heroicon-o-bell-slash')
                    ->color(fn (MonitorApis $record): string => $record->isSilenced() ? 'warning' : 'gray')
                    ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                    ->modalHeading('Snooze notifications for this API monitor')
                    ->modalDescription('Suppress alert delivery during a maintenance window. Checks keep running, the application status keeps updating, but no emails or webhooks fire while snoozed.')
                    ->modalSubmitActionLabel('Snooze')
                    ->fillForm(fn (MonitorApis $record): array => [
                        'duration' => $record->isSilenced() ? 'custom' : '1h',
                        'until' => $record->silenced_until,
                    ])
                    ->schema(MonitorSnoozeAction::formSchema())
                    ->action(function (MonitorApis $record, array $data): void {
                        $until = MonitorSnoozeAction::resolveUntil($data);

                        if ($until === null) {
                            Notification::make()
                                ->title('Snooze time must be in the future')
                                ->body('Pick a future moment, or use Unsnooze to clear the silence.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update(['silenced_until' => $until]);

                        Notification::make()
                            ->title('Notifications snoozed')
                            ->body("Alerts paused for {$record->title} until {$until->format('M j, Y H:i')}.")
                            ->success()
                            ->send();
                    }),
                Action::make('unsnooze')
                    ->label('Unsnooze')
                    ->icon('heroicon-o-bell')
                    ->color('gray')
                    ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                    ->visible(fn (MonitorApis $record): bool => $record->isSilenced())
                    ->requiresConfirmation()
                    ->modalHeading('Resume notifications')
                    ->modalDescription('Notifications for this API monitor will fire again on the next status change.')
                    ->modalSubmitActionLabel('Unsnooze')
                    ->action(function (MonitorApis $record): void {
                        $record->update(['silenced_until' => null]);

                        Notification::make()
                            ->title('Notifications resumed')
                            ->body("{$record->title} will alert again on the next status change.")
                            ->success()
                            ->send();
                    }),
                Action::make('run_now')
                    ->label('Run check now')
                    ->color('primary')
                    ->icon('heroicon-o-bolt')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-bolt')
                    ->modalHeading('Run API monitor now')
                    ->modalDescription('Checkybot will queue a real request against this endpoint, append the result to run history, update live status, and alert subscribers on status changes.')
                    ->modalSubmitActionLabel('Run now')
                    ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                    ->visible(fn (MonitorApis $record): bool => $record->deleted_at === null && (bool) $record->is_enabled)
                    ->disabled(fn (MonitorApis $record): bool => $record->hasQueuedDiagnostic())
                    ->action(function (MonitorApis $record): void {
                        $queuedStatePersisted = false;

                        try {
                            $record->refresh();

                            if ($record->deleted_at !== null || ! $record->is_enabled) {
                                Notification::make()
                                    ->title('Diagnostic unavailable')
                                    ->body('Only active package-managed API monitors can be run on demand.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            if ($record->hasQueuedDiagnostic()) {
                                Notification::make()
                                    ->title('Diagnostic already queued')
                                    ->body('Checkybot is already waiting for this API monitor diagnostic to finish.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $record->forceFill([
                                'diagnostic_queued_at' => now(),
                            ])->save();
                            $queuedStatePersisted = true;

                            RunApiMonitorDiagnosticJob::dispatch($record->withoutRelations());
                        } catch (\Throwable $e) {
                            if ($queuedStatePersisted) {
                                $record->forceFill([
                                    'diagnostic_queued_at' => null,
                                ])->save();
                            }

                            Log::error('Run Now package-managed API diagnostic dispatch failed from project relation action', [
                                'monitor_api_id' => $record->id,
                                'project_id' => $record->project_id,
                                'exception' => $e,
                            ]);

                            Notification::make()
                                ->title('Diagnostic could not be queued')
                                ->body('Checkybot could not queue the on-demand check. Check the application logs for details.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Diagnostic queued')
                            ->body('Checkybot will run this API monitor in the background and add the evidence to diagnostic history.')
                            ->success()
                            ->send();
                    }),
                ViewAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('assign_component')
                        ->label('Assign component')
                        ->icon('heroicon-o-squares-plus')
                        ->color('gray')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                        ->modalHeading('Assign selected APIs to a component')
                        ->modalDescription('Move selected package-managed API checks under an existing application component, or create a new component and assign them in one step.')
                        ->modalSubmitActionLabel('Assign component')
                        ->schema($this->componentAssignmentSchema())
                        ->action(function (Collection $records, array $data): void {
                            $this->assignSelectedComponent($records, $data);
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('run_selected_diagnostics')
                        ->label('Run selected diagnostics')
                        ->icon('heroicon-o-bolt')
                        ->color('primary')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:MonitorApis') ?? false)
                        ->requiresConfirmation()
                        ->modalIcon('heroicon-o-bolt')
                        ->modalHeading('Run selected API diagnostics')
                        ->modalDescription('Checkybot will queue fresh diagnostics for selected active API monitors and skip archived, disabled, or already queued rows.')
                        ->modalSubmitActionLabel('Run diagnostics')
                        ->action(function (Collection $records): void {
                            $this->queueSelectedDiagnostics($records);
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['latestResult', 'latestScheduledResult', 'latestDiagnosticResult'])
                ->withoutGlobalScopes([
                    SoftDeletingScope::class,
                ]))
            ->defaultSort('title');
    }

    private function monitoringState(MonitorApis $record): string
    {
        if ($record->deleted_at) {
            return 'Archived';
        }

        if ($record->is_enabled === false) {
            return 'Disabled';
        }

        return 'Active';
    }

    private function queueSelectedDiagnostics(Collection $records): void
    {
        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($records as $record) {
            $queuedStatePersisted = false;

            try {
                $record->refresh();

                if ($record->deleted_at !== null || ! $record->is_enabled || $record->hasQueuedDiagnostic()) {
                    $skipped++;

                    continue;
                }

                $record->forceFill([
                    'diagnostic_queued_at' => now(),
                ])->save();
                $queuedStatePersisted = true;

                RunApiMonitorDiagnosticJob::dispatch($record->withoutRelations());
                $queued++;
            } catch (\Throwable $e) {
                $failed++;

                if ($queuedStatePersisted) {
                    $record->forceFill([
                        'diagnostic_queued_at' => null,
                    ])->save();
                }

                Log::error('Bulk package-managed API diagnostic dispatch failed from project relation action', [
                    'monitor_api_id' => $record->id,
                    'project_id' => $record->project_id,
                    'exception' => $e,
                ]);
            }
        }

        $this->sendBulkDiagnosticsNotification($queued, $skipped, $failed);
    }

    private function componentAssignmentSchema(): array
    {
        return [
            Select::make('project_component_id')
                ->label('Existing component')
                ->options(fn (): array => $this->existingComponentOptions())
                ->searchable()
                ->preload()
                ->placeholder('Choose a component'),
            TextInput::make('component_name')
                ->label('New component')
                ->placeholder('checkout')
                ->maxLength(255)
                ->helperText('Leave this blank to use the selected existing component. If the name already exists, Checkybot will use that component.'),
        ];
    }

    private function existingComponentOptions(): array
    {
        return ProjectComponent::query()
            ->where('project_id', $this->getOwnerRecord()->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function assignSelectedComponent(Collection $records, array $data): void
    {
        $component = $this->resolveAssignmentComponent($data);

        if ($component === null) {
            return;
        }

        $count = MonitorApis::withTrashed()
            ->where('project_id', $this->getOwnerRecord()->getKey())
            ->where('source', 'package')
            ->whereIn('id', $records->pluck('id'))
            ->update(['project_component_id' => $component->id]);

        Notification::make()
            ->title($count === 1 ? '1 API assigned' : "{$count} APIs assigned")
            ->body("Selected API checks now belong to {$component->name}.")
            ->success()
            ->send();
    }

    private function resolveAssignmentComponent(array $data): ?ProjectComponent
    {
        $name = trim((string) ($data['component_name'] ?? ''));

        if ($name !== '') {
            $existing = ProjectComponent::query()
                ->where('project_id', $this->getOwnerRecord()->getKey())
                ->where('name', $name)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if (! (auth()->user()?->can('Create:ProjectComponent') ?? false)) {
                Notification::make()
                    ->title('Component could not be created')
                    ->body('You can assign existing components, but you do not have permission to create a new one.')
                    ->danger()
                    ->send();

                return null;
            }

            return ProjectComponent::query()->create([
                'project_id' => $this->getOwnerRecord()->getKey(),
                'name' => $name,
                'source' => 'manual',
                'declared_interval' => '5m',
                'interval_minutes' => 5,
                'current_status' => 'unknown',
                'last_reported_status' => 'unknown',
                'summary' => 'Awaiting active child check results',
                'metrics' => [],
                'is_archived' => false,
                'created_by' => auth()->id(),
            ]);
        }

        $componentId = $data['project_component_id'] ?? null;

        if ($componentId !== null && $componentId !== '') {
            return ProjectComponent::query()
                ->where('project_id', $this->getOwnerRecord()->getKey())
                ->whereKey($componentId)
                ->first();
        }

        Notification::make()
            ->title('Choose a component')
            ->body('Select an existing component or enter a new component name before assigning checks.')
            ->danger()
            ->send();

        return null;
    }

    private function sendBulkDiagnosticsNotification(int $queued, int $skipped, int $failed): void
    {
        $skippedMessage = $skipped > 0
            ? " Skipped {$skipped} archived, disabled, or already queued ".str('row')->plural($skipped).'.'
            : '';

        if ($queued === 0 && $failed === 0) {
            Notification::make()
                ->title('No diagnostics queued')
                ->body('Every selected API monitor was archived, disabled, or already queued.')
                ->warning()
                ->send();

            return;
        }

        if ($failed > 0) {
            Notification::make()
                ->title($queued === 0 ? 'Diagnostics could not be queued' : "{$queued} ".str('diagnostic')->plural($queued).' queued')
                ->body("Checkybot could not queue {$failed} selected API ".str('diagnostic')->plural($failed).'. Check the application logs for details.'.$skippedMessage)
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($queued === 1 ? '1 diagnostic queued' : "{$queued} diagnostics queued")
            ->body('Checkybot will run the selected API monitors in the background and add the evidence to diagnostic history.'.$skippedMessage)
            ->success()
            ->send();
    }

    private function monitoringStateDescription(MonitorApis $record): ?string
    {
        if (! $record->deleted_at && $record->is_enabled === false) {
            return 'This check is disabled. Scheduled runs are paused.';
        }

        return null;
    }

    private function applyMonitoringStateFilter(Builder $query, ?string $state): Builder
    {
        return match ($state) {
            'active' => $query
                ->whereNull('deleted_at')
                ->where('is_enabled', true),
            'disabled' => $query
                ->whereNull('deleted_at')
                ->where('is_enabled', false),
            'archived' => $query->whereNotNull('deleted_at'),
            default => $query,
        };
    }

    private function componentFilterOptions(): array
    {
        return [self::UNMAPPED_COMPONENT_FILTER => 'Unmapped'] + ProjectComponent::query()
            ->where('project_id', $this->getOwnerRecord()->getKey())
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function applyComponentFilter(Builder $query, ?string $componentId): Builder
    {
        return match ($componentId) {
            self::UNMAPPED_COMPONENT_FILTER => $query->whereNull('project_component_id'),
            null, '' => $query,
            default => $query->where('project_component_id', $componentId),
        };
    }

    private function applyManualRunStatusFilter(Builder $query, ?string $state): Builder
    {
        return match ($state) {
            'queued' => $this->applyQueuedManualRunFilter($query),
            'drift' => $this->applyCompletedManualRunFilter($query)
                ->whereHas('latestDiagnosticResult', fn (Builder $query): Builder => $query
                    ->whereRaw("COALESCE(monitor_api_results.status, '__missing__') <> COALESCE(monitor_apis.current_status, '__missing__')")),
            'matching' => $this->applyCompletedManualRunFilter($query)
                ->whereHas('latestDiagnosticResult', fn (Builder $query): Builder => $query
                    ->whereRaw("COALESCE(monitor_api_results.status, '__missing__') = COALESCE(monitor_apis.current_status, '__missing__')")),
            'missing' => $query
                ->whereNull('diagnostic_queued_at')
                ->whereDoesntHave('latestDiagnosticResult'),
            default => $query,
        };
    }

    private function applyQueuedManualRunFilter(Builder $query): Builder
    {
        return $query
            ->whereNotNull('diagnostic_queued_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereDoesntHave('latestDiagnosticResult')
                ->orWhereHas('latestDiagnosticResult', fn (Builder $query): Builder => $query
                    ->whereColumn('monitor_apis.diagnostic_queued_at', '>', 'monitor_api_results.created_at')));
    }

    private function applyCompletedManualRunFilter(Builder $query): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query
            ->whereNull('diagnostic_queued_at')
            ->orWhereHas('latestDiagnosticResult', fn (Builder $query): Builder => $query
                ->whereColumn('monitor_apis.diagnostic_queued_at', '<=', 'monitor_api_results.created_at')));
    }

    private function manualRunDescription(MonitorApis $record): ?string
    {
        if ($record->hasQueuedDiagnostic()) {
            return 'Manual run is queued.';
        }

        if ($record->latestDiagnosticResult === null) {
            return null;
        }

        if ($this->manualRunDrifts($record)) {
            return 'Differs from live health.';
        }

        return 'Matches live health.';
    }

    private function manualRunDrifts(MonitorApis $record): bool
    {
        return ! $record->hasQueuedDiagnostic()
            && $record->latestDiagnosticResult !== null
            && $record->latestDiagnosticResult->status !== $record->current_status;
    }
}
