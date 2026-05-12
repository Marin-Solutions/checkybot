<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Jobs\RunApiMonitorDiagnosticJob;
use App\Models\MonitorApis;
use App\Support\PackageCheckTableEvidence;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;

class PackageManagedApisRelationManager extends RelationManager
{
    protected static string $relationship = 'packageManagedApis';

    protected static ?string $title = 'Package-managed APIs';

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
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
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
                TextColumn::make('last_heartbeat_at')
                    ->label('Last Heartbeat')
                    ->state(fn (MonitorApis $record): ?string => $record->last_heartbeat_at?->toDayDateTimeString())
                    ->description(fn (MonitorApis $record): ?string => $record->last_heartbeat_at?->diffForHumans())
                    ->default('-'),
                TextColumn::make('freshness_evidence')
                    ->label('Freshness')
                    ->state(fn (MonitorApis $record): string => PackageCheckTableEvidence::freshnessState($record))
                    ->badge()
                    ->color(fn (string $state): string => PackageCheckTableEvidence::freshnessColor($state))
                    ->description(fn (MonitorApis $record): ?string => PackageCheckTableEvidence::freshnessDescription($record)),
                TextColumn::make('package_interval')
                    ->label('Interval'),
            ])
            ->recordActions([
                Action::make('run_now')
                    ->label('Run check now')
                    ->color('primary')
                    ->icon('heroicon-o-bolt')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-bolt')
                    ->modalHeading('Run API monitor now')
                    ->modalDescription('Checkybot will queue a real heartbeat against this endpoint and append the result to its diagnostic history when it completes. The monitor\'s live status is reserved for the scheduler, so this manual run will not move the dashboard or alert subscribers.')
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
            ->modifyQueryUsing(fn (Builder $query) => $query
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

    private function monitoringStateDescription(MonitorApis $record): ?string
    {
        if (! $record->deleted_at && $record->is_enabled === false) {
            return 'This check is disabled. Scheduled runs are paused.';
        }

        return null;
    }
}
