<?php

namespace App\Filament\Resources\ProjectComponents\Tables;

use App\Filament\Resources\Support\MonitorSnoozeAction;
use App\Filament\Support\HealthStatusFilter;
use App\Models\ProjectComponent;
use App\Support\HealthStatusLabel;
use App\Support\ProjectComponentDeliveryState;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ProjectComponentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('project.name')
                    ->label('Application')
                    ->searchable(),
                TextColumn::make('current_status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('delivery_state')
                    ->label('Delivery State')
                    ->state(fn (ProjectComponent $record): string => ProjectComponentDeliveryState::label($record))
                    ->badge()
                    ->color(fn (string $state): string => ProjectComponentDeliveryState::color($state)),
                TextColumn::make('silenced_until')
                    ->label('Snoozed')
                    ->badge()
                    ->color('warning')
                    ->icon('heroicon-o-bell-slash')
                    ->state(fn (ProjectComponent $record): ?string => $record->isSilenced()
                        ? 'Until '.$record->silenced_until->format('M j, H:i')
                        : null)
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('declared_interval')
                    ->label('Interval'),
                TextColumn::make('last_heartbeat_at')
                    ->sinceInUserZone(),
            ])
            ->filters([
                HealthStatusFilter::makeForNonNullableColumn(),
                SelectFilter::make('delivery_state')
                    ->label('Delivery State')
                    ->options(ProjectComponentDeliveryState::options())
                    ->query(fn (Builder $query, array $data): Builder => ProjectComponentDeliveryState::applyFilter(
                        $query,
                        $data['value'] ?? null,
                    )),
                HealthStatusFilter::onlyFailing(
                    activeScope: fn (Builder $query): Builder => $query->where('is_archived', false),
                ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('snooze')
                    ->label(fn (ProjectComponent $record): string => $record->isSilenced() ? 'Snoozed' : 'Snooze')
                    ->icon('heroicon-o-bell-slash')
                    ->color(fn (ProjectComponent $record): string => $record->isSilenced() ? 'warning' : 'gray')
                    ->authorize(fn (): bool => auth()->user()?->can('Update:ProjectComponent') ?? false)
                    ->modalHeading('Snooze notifications for this component')
                    ->modalDescription('Suppress alert delivery during a maintenance window. Heartbeats keep updating the component, but no emails or webhooks fire while snoozed.')
                    ->modalSubmitActionLabel('Snooze')
                    ->fillForm(fn (ProjectComponent $record): array => [
                        'duration' => $record->isSilenced() ? 'custom' : '1h',
                        'until' => $record->silenced_until,
                    ])
                    ->schema(MonitorSnoozeAction::formSchema())
                    ->action(function (ProjectComponent $record, array $data): void {
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
                    ->authorize(fn (): bool => auth()->user()?->can('Update:ProjectComponent') ?? false)
                    ->visible(fn (ProjectComponent $record): bool => $record->isSilenced())
                    ->requiresConfirmation()
                    ->modalHeading('Resume notifications')
                    ->modalDescription('Notifications for this component will fire again on the next status change or stale heartbeat.')
                    ->modalSubmitActionLabel('Unsnooze')
                    ->action(function (ProjectComponent $record): void {
                        $record->update(['silenced_until' => null]);

                        Notification::make()
                            ->title('Notifications resumed')
                            ->body("{$record->name} will alert again on the next status change.")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('snooze')
                        ->label('Snooze notifications')
                        ->icon('heroicon-o-bell-slash')
                        ->color('warning')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:ProjectComponent') ?? false)
                        ->modalHeading('Snooze notifications for selected components')
                        ->modalDescription('Suppress alert delivery during a maintenance window. Heartbeats keep updating these components, but no emails or webhooks fire while snoozed.')
                        ->modalSubmitActionLabel('Snooze')
                        ->schema(MonitorSnoozeAction::formSchema())
                        ->action(function (Collection $records, array $data): void {
                            $until = MonitorSnoozeAction::resolveUntil($data);

                            if ($until === null) {
                                Notification::make()
                                    ->title('Snooze time must be in the future')
                                    ->body('Pick a future moment, or use Unsnooze to clear the silence.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $count = ProjectComponent::query()
                                ->whereIn('id', $records->pluck('id'))
                                ->update(['silenced_until' => $until]);

                            Notification::make()
                                ->title($count === 1 ? '1 component snoozed' : "{$count} components snoozed")
                                ->body("Alerts paused until {$until->format('M j, Y H:i')}.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('unsnooze')
                        ->label('Unsnooze')
                        ->icon('heroicon-o-bell')
                        ->color('gray')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:ProjectComponent') ?? false)
                        ->requiresConfirmation()
                        ->modalHeading('Unsnooze selected components')
                        ->modalDescription('Notifications will resume immediately on the next status change or stale heartbeat for these components.')
                        ->modalSubmitActionLabel('Unsnooze')
                        ->action(function (Collection $records): void {
                            $ids = $records->whereNotNull('silenced_until')->pluck('id');
                            $count = $ids->isEmpty()
                                ? 0
                                : ProjectComponent::query()->whereIn('id', $ids)->update(['silenced_until' => null]);

                            Notification::make()
                                ->title($count === 0
                                    ? 'Nothing to unsnooze'
                                    : ($count === 1 ? '1 component unsnoozed' : "{$count} components unsnoozed"))
                                ->body($count === 0
                                    ? 'None of the selected components had an active snooze.'
                                    : 'Notifications will resume on the next status change.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('enable')
                        ->label('Enable')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:ProjectComponent') ?? false)
                        ->requiresConfirmation()
                        ->modalHeading('Enable selected components')
                        ->modalDescription('Selected components will be un-archived and will resume tracking incoming heartbeats.')
                        ->modalSubmitActionLabel('Enable')
                        ->action(function (Collection $records): void {
                            $ids = $records->where('is_archived', true)->pluck('id');
                            $count = $ids->isEmpty()
                                ? 0
                                : ProjectComponent::query()->whereIn('id', $ids)->update([
                                    'is_archived' => false,
                                    'project_paused_monitoring' => false,
                                    'archived_at' => null,
                                    'archive_reason' => null,
                                ]);

                            Notification::make()
                                ->title($count === 0
                                    ? 'Nothing to enable'
                                    : ($count === 1 ? '1 component enabled' : "{$count} components enabled"))
                                ->body($count === 0
                                    ? 'Every selected component was already active.'
                                    : 'Heartbeat tracking has resumed for these components.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('disable')
                        ->label('Disable')
                        ->icon('heroicon-o-pause')
                        ->color('warning')
                        ->authorize(fn (): bool => auth()->user()?->can('Update:ProjectComponent') ?? false)
                        ->requiresConfirmation()
                        ->modalHeading('Disable selected components')
                        ->modalDescription('Selected components will be archived. Incoming heartbeats are ignored and stale alerts will stop firing, but history is preserved.')
                        ->modalSubmitActionLabel('Disable')
                        ->action(function (Collection $records): void {
                            $ids = $records->where('is_archived', false)->pluck('id');
                            $count = $ids->isEmpty()
                                ? 0
                                : ProjectComponent::query()->whereIn('id', $ids)->update([
                                    'is_archived' => true,
                                    'project_paused_monitoring' => false,
                                    'archived_at' => now(),
                                    'archive_reason' => ProjectComponent::ARCHIVE_REASON_USER,
                                ]);

                            Notification::make()
                                ->title($count === 0
                                    ? 'Nothing to disable'
                                    : ($count === 1 ? '1 component disabled' : "{$count} components disabled"))
                                ->body($count === 0
                                    ? 'Every selected component was already archived.'
                                    : 'These components are now archived and will no longer fire stale alerts.')
                                ->warning()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No application components yet')
            ->emptyStateDescription('Add a component (cron job, queue worker, or background process) and point its heartbeat at Checkybot to detect stalls and missed runs.')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Add component')
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
