<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Resources\Support\MonitorSnoozeAction;
use App\Jobs\LogUptimeSslJob;
use App\Models\Website;
use App\Support\PackageCheckTableEvidence;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;

class PackageManagedWebsitesRelationManager extends RelationManager
{
    protected static string $relationship = 'packageManagedWebsites';

    protected static ?string $title = 'Package-managed Websites';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('url')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('check_types')
                    ->label('Checks')
                    ->state(function ($record): string {
                        return collect([
                            $record->uptime_check ? 'Uptime' : null,
                            $record->ssl_check ? 'SSL' : null,
                        ])->filter()->implode(', ');
                    })
                    ->badge(),
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
                    ->state(fn (Website $record): string => $this->monitoringState($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Disabled' => 'warning',
                        'Archived' => 'gray',
                        default => 'gray',
                    })
                    ->description(fn (Website $record): ?string => $this->monitoringStateDescription($record)),
                TextColumn::make('status_summary')
                    ->label('Summary')
                    ->wrap()
                    ->limit(90)
                    ->default('-'),
                TextColumn::make('last_heartbeat_at')
                    ->label('Last Heartbeat')
                    ->state(fn (Website $record): ?string => $record->last_heartbeat_at?->toDayDateTimeString())
                    ->description(fn (Website $record): ?string => $record->last_heartbeat_at?->diffForHumans())
                    ->default('-'),
                TextColumn::make('freshness_evidence')
                    ->label('Freshness')
                    ->state(fn (Website $record): string => PackageCheckTableEvidence::freshnessState($record))
                    ->badge()
                    ->color(fn (string $state): string => PackageCheckTableEvidence::freshnessColor($state))
                    ->description(fn (Website $record): ?string => PackageCheckTableEvidence::freshnessDescription($record)),
                TextColumn::make('silenced_until')
                    ->label('Snoozed')
                    ->badge()
                    ->color('warning')
                    ->icon('heroicon-o-bell-slash')
                    ->state(fn (Website $record): ?string => $record->isSilenced()
                        ? 'Until '.$record->silenced_until->format('M j, H:i')
                        : null)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('package_interval')
                    ->label('Interval'),
            ])
            ->filters([
                SelectFilter::make('freshness_evidence')
                    ->label('Freshness')
                    ->options(PackageCheckTableEvidence::freshnessFilterOptions())
                    ->query(fn (Builder $query, array $data): Builder => PackageCheckTableEvidence::applyWebsiteFreshnessFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
            ])
            ->recordActions([
                Action::make('snooze')
                    ->label(fn (Website $record): string => $record->isSilenced() ? 'Snoozed' : 'Snooze')
                    ->icon('heroicon-o-bell-slash')
                    ->color(fn (Website $record): string => $record->isSilenced() ? 'warning' : 'gray')
                    ->authorize(fn (): bool => auth()->user()?->can('Update:Website') ?? false)
                    ->modalHeading('Snooze notifications for this website')
                    ->modalDescription('Suppress alert delivery during a maintenance window. Checks keep running, the application status keeps updating, but no emails or webhooks fire while snoozed.')
                    ->modalSubmitActionLabel('Snooze')
                    ->fillForm(fn (Website $record): array => [
                        'duration' => $record->isSilenced() ? 'custom' : '1h',
                        'until' => $record->silenced_until,
                    ])
                    ->schema(MonitorSnoozeAction::formSchema())
                    ->action(function (Website $record, array $data): void {
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
                            ->body("Alerts paused for {$record->name} until {$until->format('M j, Y H:i')}.")
                            ->success()
                            ->send();
                    }),
                Action::make('unsnooze')
                    ->label('Unsnooze')
                    ->icon('heroicon-o-bell')
                    ->color('gray')
                    ->authorize(fn (): bool => auth()->user()?->can('Update:Website') ?? false)
                    ->visible(fn (Website $record): bool => $record->isSilenced())
                    ->requiresConfirmation()
                    ->modalHeading('Resume notifications')
                    ->modalDescription('Notifications for this website will fire again on the next status change.')
                    ->modalSubmitActionLabel('Unsnooze')
                    ->action(function (Website $record): void {
                        $record->update(['silenced_until' => null]);

                        Notification::make()
                            ->title('Notifications resumed')
                            ->body("{$record->name} will alert again on the next status change.")
                            ->success()
                            ->send();
                    }),
                Action::make('run_now')
                    ->label('Run check now')
                    ->color('primary')
                    ->icon('heroicon-o-bolt')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-bolt')
                    ->modalHeading('Run website diagnostics now')
                    ->modalDescription('Checkybot will queue the enabled diagnostics for this website and append the result to its diagnostic history when they complete. The website\'s live status is reserved for the scheduler, so this manual run will not move the dashboard or alert subscribers.')
                    ->modalSubmitActionLabel('Run now')
                    ->authorize(fn (): bool => auth()->user()?->can('Update:Website') ?? false)
                    ->visible(fn (Website $record): bool => $record->deleted_at === null && ((bool) $record->uptime_check || (bool) $record->ssl_check))
                    ->disabled(fn (Website $record): bool => $record->hasQueuedDiagnostic())
                    ->action(function (Website $record): void {
                        $queuedStatePersisted = false;

                        try {
                            $record->refresh();

                            if ($record->deleted_at !== null || (! $record->uptime_check && ! $record->ssl_check)) {
                                Notification::make()
                                    ->title('Diagnostic unavailable')
                                    ->body('Only active package-managed website checks can be run on demand.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            if ($record->hasQueuedDiagnostic()) {
                                Notification::make()
                                    ->title('Diagnostic already queued')
                                    ->body('Checkybot is already waiting for this website diagnostic to finish.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $record->forceFill([
                                'diagnostic_queued_at' => now(),
                            ])->save();
                            $queuedStatePersisted = true;

                            LogUptimeSslJob::dispatch($record->withoutRelations(), onDemand: true);
                        } catch (\Throwable $e) {
                            if ($queuedStatePersisted) {
                                $record->forceFill([
                                    'diagnostic_queued_at' => null,
                                ])->save();
                            }

                            Log::error('Run Now package-managed website diagnostic dispatch failed from project relation action', [
                                'website_id' => $record->id,
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
                            ->body('Checkybot will run this website check in the background and add the evidence to diagnostic history.')
                            ->success()
                            ->send();
                    }),
                ViewAction::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withoutGlobalScopes([
                    SoftDeletingScope::class,
                ]))
            ->defaultSort('name');
    }

    private function monitoringState(Website $record): string
    {
        if ($record->deleted_at) {
            return 'Archived';
        }

        if (! $record->uptime_check && ! $record->ssl_check) {
            return 'Disabled';
        }

        return 'Active';
    }

    private function monitoringStateDescription(Website $record): ?string
    {
        if (! $record->deleted_at && ! $record->uptime_check && ! $record->ssl_check) {
            return 'Both uptime and SSL checks are disabled. Scheduled runs are paused.';
        }

        return null;
    }
}
