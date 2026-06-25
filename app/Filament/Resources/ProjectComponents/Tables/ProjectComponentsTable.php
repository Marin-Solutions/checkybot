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
    private const FAILING_CHILD_STATUSES = ['warning', 'danger'];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['project:id,name'])
                ->withCount([
                    'activeMonitorApis as active_monitor_apis_count',
                    'activeWebsites as active_websites_count',
                    'activeMonitorApis as active_danger_monitor_apis_count' => fn (Builder $query): Builder => $query
                        ->where('current_status', 'danger'),
                    'activeWebsites as active_danger_websites_count' => fn (Builder $query): Builder => $query
                        ->where('current_status', 'danger'),
                    'activeMonitorApis as active_warning_monitor_apis_count' => fn (Builder $query): Builder => $query
                        ->where('current_status', 'warning'),
                    'activeWebsites as active_warning_websites_count' => fn (Builder $query): Builder => $query
                        ->where('current_status', 'warning'),
                    'activeMonitorApis as active_healthy_monitor_apis_count' => fn (Builder $query): Builder => $query
                        ->where('current_status', 'healthy'),
                    'activeWebsites as active_healthy_websites_count' => fn (Builder $query): Builder => $query
                        ->where('current_status', 'healthy'),
                    'activeMonitorApis as active_failing_monitor_apis_count' => fn (Builder $query): Builder => $query
                        ->whereIn('current_status', self::FAILING_CHILD_STATUSES),
                    'activeWebsites as active_failing_websites_count' => fn (Builder $query): Builder => $query
                        ->whereIn('current_status', self::FAILING_CHILD_STATUSES),
                ]))
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('project.name')
                    ->label('Application')
                    ->searchable(),
                TextColumn::make('current_status')
                    ->state(fn (ProjectComponent $record): string => self::derivedCurrentStatus($record))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => HealthStatusLabel::format($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('active_failing_monitor_apis_count')
                    ->label('Failing APIs')
                    ->state(fn (ProjectComponent $record): int => self::activeFailingMonitorApisCount($record))
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn (int $state): string => number_format($state))
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('active_failing_websites_count')
                    ->label('Failing Websites')
                    ->state(fn (ProjectComponent $record): int => self::activeFailingWebsitesCount($record))
                    ->badge()
                    ->alignCenter()
                    ->formatStateUsing(fn (int $state): string => number_format($state))
                    ->color(fn (int $state): string => $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('delivery_state')
                    ->label('Delivery State')
                    ->state(fn (ProjectComponent $record): string => self::deliveryStateLabel($record))
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
            ->recordActions(static::recordActions())
            ->toolbarActions([
                BulkActionGroup::make(static::bulkActions()),
            ])
            ->emptyStateHeading('No application components yet')
            ->emptyStateDescription('Add a component and link active package checks to derive its health.')
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Add component')
                    ->icon('heroicon-o-plus'),
            ]);
    }

    private static function activeFailingMonitorApisCount(ProjectComponent $record): int
    {
        if ($record->active_failing_monitor_apis_count !== null) {
            return (int) $record->active_failing_monitor_apis_count;
        }

        if ($record->relationLoaded('activeMonitorApis')) {
            return $record->activeMonitorApis
                ->whereIn('current_status', self::FAILING_CHILD_STATUSES)
                ->count();
        }

        return $record->activeMonitorApis()
            ->whereIn('current_status', self::FAILING_CHILD_STATUSES)
            ->count();
    }

    private static function activeFailingWebsitesCount(ProjectComponent $record): int
    {
        if ($record->active_failing_websites_count !== null) {
            return (int) $record->active_failing_websites_count;
        }

        if ($record->relationLoaded('activeWebsites')) {
            return $record->activeWebsites
                ->whereIn('current_status', self::FAILING_CHILD_STATUSES)
                ->count();
        }

        return $record->activeWebsites()
            ->whereIn('current_status', self::FAILING_CHILD_STATUSES)
            ->count();
    }

    private static function derivedCurrentStatus(ProjectComponent $record): string
    {
        if (! self::hasStatusRollupCounts($record)) {
            return $record->derivedCurrentStatus();
        }

        if ((bool) $record->is_archived) {
            return 'unknown';
        }

        $dangerCount = self::countAttribute($record, 'active_danger_monitor_apis_count')
            + self::countAttribute($record, 'active_danger_websites_count');

        if ($dangerCount > 0) {
            return 'danger';
        }

        $warningCount = self::countAttribute($record, 'active_warning_monitor_apis_count')
            + self::countAttribute($record, 'active_warning_websites_count');

        if ($warningCount > 0) {
            return 'warning';
        }

        $activeCount = self::countAttribute($record, 'active_monitor_apis_count')
            + self::countAttribute($record, 'active_websites_count');

        if ($activeCount === 0) {
            if ($record->source !== 'package' && in_array($record->current_status, ['healthy', 'warning', 'danger'], true)) {
                return $record->current_status;
            }

            return 'pending';
        }

        $healthyCount = self::countAttribute($record, 'active_healthy_monitor_apis_count')
            + self::countAttribute($record, 'active_healthy_websites_count');

        return $healthyCount === $activeCount ? 'healthy' : 'pending';
    }

    private static function deliveryStateLabel(ProjectComponent $record): string
    {
        return ProjectComponentDeliveryState::options()[match (true) {
            $record->is_archived => ProjectComponentDeliveryState::ARCHIVED,
            $record->isSilenced() => ProjectComponentDeliveryState::SNOOZED,
            self::derivedCurrentStatus($record) === 'pending' => ProjectComponentDeliveryState::PENDING,
            default => ProjectComponentDeliveryState::ACTIVE,
        }];
    }

    private static function countAttribute(ProjectComponent $record, string $attribute): int
    {
        return (int) ($record->getAttribute($attribute) ?? 0);
    }

    private static function hasStatusRollupCounts(ProjectComponent $record): bool
    {
        return array_key_exists('active_monitor_apis_count', $record->getAttributes())
            && array_key_exists('active_websites_count', $record->getAttributes());
    }

    public static function recordActions(bool $includeEdit = true): array
    {
        return array_values(array_filter([
            ViewAction::make(),
            $includeEdit ? EditAction::make() : null,
            Action::make('snooze')
                ->label(fn (ProjectComponent $record): string => $record->isSilenced() ? 'Snoozed' : 'Snooze')
                ->icon('heroicon-o-bell-slash')
                ->color(fn (ProjectComponent $record): string => $record->isSilenced() ? 'warning' : 'gray')
                ->authorize(fn (): bool => auth()->user()?->can('Update:ProjectComponent') ?? false)
                ->modalHeading('Snooze notifications for this component')
                ->modalDescription('Suppress alert delivery during a maintenance window. Derived health keeps updating, but no emails or webhooks fire while snoozed.')
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
                ->modalDescription('Notifications for this component will fire again on the next status change.')
                ->modalSubmitActionLabel('Unsnooze')
                ->action(function (ProjectComponent $record): void {
                    $record->update(['silenced_until' => null]);

                    Notification::make()
                        ->title('Notifications resumed')
                        ->body("{$record->name} will alert again on the next status change.")
                        ->success()
                        ->send();
                }),
        ]));
    }

    public static function bulkActions(bool $includeDelete = true): array
    {
        return array_values(array_filter([
            BulkAction::make('snooze')
                ->label('Snooze notifications')
                ->icon('heroicon-o-bell-slash')
                ->color('warning')
                ->authorize(fn (): bool => auth()->user()?->can('Update:ProjectComponent') ?? false)
                ->modalHeading('Snooze notifications for selected components')
                ->modalDescription('Suppress alert delivery during a maintenance window. Derived health keeps updating, but no emails or webhooks fire while snoozed.')
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
                ->modalDescription('Notifications will resume immediately on the next status change for these components.')
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
                ->modalDescription('Selected components will be un-archived and resume derived health tracking.')
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
                            : 'Derived health tracking has resumed for these components.')
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
                ->modalDescription('Selected components will be archived. Active child checks remain visible as metadata but no longer affect component health.')
                ->modalSubmitActionLabel('Disable')
                ->action(function (Collection $records): void {
                    $ids = $records->where('is_archived', false)->pluck('id');
                    $count = $ids->isEmpty()
                        ? 0
                        : ProjectComponent::query()->whereIn('id', $ids)->update(ProjectComponent::disabledHealthAttributes() + [
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
                            : 'These components are now archived and excluded from component health.')
                        ->warning()
                        ->send();
                })
                ->deselectRecordsAfterCompletion(),
            $includeDelete ? DeleteBulkAction::make() : null,
        ]));
    }
}
