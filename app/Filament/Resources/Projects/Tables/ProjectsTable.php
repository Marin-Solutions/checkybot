<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\Website;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('application_status')
                    ->label('Current Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : 'Unknown')
                    ->color(fn (?string $state): string => match ($state) {
                        'healthy' => 'success',
                        'warning' => 'warning',
                        'danger' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('environment')
                    ->badge()
                    ->default('Unknown'),
                TextColumn::make('technology')
                    ->default('-'),
                TextColumn::make('components_count')
                    ->label('Components')
                    ->state(fn (Project $record): int => $record->components_count ?? $record->components()->count()),
                TextColumn::make('created_at')
                    ->dateTimeInUserZone()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('application_status')
                    ->label('Current Status')
                    ->options([
                        'healthy' => 'Healthy',
                        'warning' => 'Warning',
                        'danger' => 'Danger',
                        'unknown' => 'Unknown',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if ($value === null || $value === '') {
                            return $query;
                        }

                        // Mirror Project::application_status(): stale tracked surfaces
                        // count as danger, and tracked surfaces without usable data
                        // count as unknown instead of being hidden behind healthy rows.
                        return match ($value) {
                            'danger' => static::whereHasDangerSurface($query),
                            'warning' => static::whereDoesntHaveMonitoredStatus(
                                static::whereHasWarningSurface($query),
                                ['danger'],
                            ),
                            'healthy' => static::whereDoesntHaveMonitoredStatus(
                                static::whereDoesntHaveUnknownSurface(
                                    static::whereHasHealthySurface($query),
                                ),
                                ['warning', 'danger'],
                            ),
                            'unknown' => static::whereHasUnknownApplicationStatus($query),
                            default => $query,
                        };
                    }),
                Filter::make('only_failing')
                    ->label('Show only failing')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => static::whereHasFailingSurface($query)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable')
                        ->label('Enable monitoring')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->authorize(fn (): bool => static::userCanCascadeMonitoring())
                        ->requiresConfirmation()
                        ->modalHeading('Enable monitoring for selected applications')
                        ->modalDescription('All website checks and API monitors tied to these applications will resume scheduled checks. Archived components will be un-archived and start accepting heartbeats again.')
                        ->modalSubmitActionLabel('Enable')
                        ->action(function (Collection $records): void {
                            $summary = static::cascadeProjectState($records, true);
                            $totalChanged = $summary['websites'] + $summary['apis'] + $summary['components'];

                            Notification::make()
                                ->title(static::summaryTitle($records->count(), true, $totalChanged))
                                ->body(static::summaryBody($summary, true))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('disable')
                        ->label('Disable monitoring')
                        ->icon('heroicon-o-pause')
                        ->color('warning')
                        ->authorize(fn (): bool => static::userCanCascadeMonitoring())
                        ->requiresConfirmation()
                        ->modalHeading('Disable monitoring for selected applications')
                        ->modalDescription('Scheduled website checks, API checks, and heartbeat tracking will pause for every website, API monitor, and component in the selected applications. Use this during maintenance windows. History and configuration are preserved.')
                        ->modalSubmitActionLabel('Disable')
                        ->action(function (Collection $records): void {
                            $summary = static::cascadeProjectState($records, false);
                            $totalChanged = $summary['websites'] + $summary['apis'] + $summary['components'];

                            Notification::make()
                                ->title(static::summaryTitle($records->count(), false, $totalChanged))
                                ->body(static::summaryBody($summary, false))
                                ->warning()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @param  array<int, string>  $statuses
     */
    protected static function whereHasMonitoredStatus(Builder $query, array $statuses): Builder
    {
        if ($statuses === ['danger']) {
            return static::whereHasDangerSurface($query);
        }

        if ($statuses === ['warning']) {
            return static::whereHasWarningSurface($query);
        }

        return $query->where(function (Builder $query) use ($statuses): void {
            $query
                ->whereHas('activeComponents', fn (Builder $components) => $components->whereIn('current_status', $statuses))
                ->orWhereHas('monitoredWebsites', fn (Builder $websites) => $websites->whereIn('current_status', $statuses))
                ->orWhereHas('enabledMonitorApis', fn (Builder $apis) => $apis->whereIn('current_status', $statuses));
        });
    }

    /**
     * @param  array<int, string>  $statuses
     */
    protected static function whereDoesntHaveMonitoredStatus(Builder $query, array $statuses): Builder
    {
        if ($statuses === ['danger']) {
            return static::whereDoesntHaveDangerSurface($query);
        }

        return $query
            ->when(
                in_array('danger', $statuses, true),
                fn (Builder $query): Builder => static::whereDoesntHaveDangerSurface($query),
            )
            ->when(
                in_array('warning', $statuses, true),
                fn (Builder $query): Builder => static::whereDoesntHaveWarningSurface($query),
            );
    }

    protected static function whereHasFailingSurface(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            static::whereHasDangerSurface($query)
                ->orWhere(function (Builder $query): void {
                    static::whereHasWarningSurface($query);
                });
        });
    }

    protected static function whereHasDangerSurface(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereHas('activeComponents', fn (Builder $components) => $components
                    ->where('is_stale', true)
                    ->orWhere('current_status', 'danger'))
                ->orWhereHas('monitoredWebsites', fn (Builder $websites) => $websites
                    ->whereNotNull('stale_at')
                    ->orWhere('current_status', 'danger'))
                ->orWhereHas('enabledMonitorApis', fn (Builder $apis) => $apis
                    ->whereNotNull('stale_at')
                    ->orWhere('current_status', 'danger'));
        });
    }

    protected static function whereDoesntHaveDangerSurface(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('activeComponents', fn (Builder $components) => $components
                ->where('is_stale', true)
                ->orWhere('current_status', 'danger'))
            ->whereDoesntHave('monitoredWebsites', fn (Builder $websites) => $websites
                ->whereNotNull('stale_at')
                ->orWhere('current_status', 'danger'))
            ->whereDoesntHave('enabledMonitorApis', fn (Builder $apis) => $apis
                ->whereNotNull('stale_at')
                ->orWhere('current_status', 'danger'));
    }

    protected static function whereHasWarningSurface(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereHas('activeComponents', fn (Builder $components) => $components->where('current_status', 'warning'))
                ->orWhereHas('monitoredWebsites', fn (Builder $websites) => $websites->where('current_status', 'warning'))
                ->orWhereHas('enabledMonitorApis', fn (Builder $apis) => $apis->where('current_status', 'warning'));
        });
    }

    protected static function whereDoesntHaveWarningSurface(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('activeComponents', fn (Builder $components) => $components->where('current_status', 'warning'))
            ->whereDoesntHave('monitoredWebsites', fn (Builder $websites) => $websites->where('current_status', 'warning'))
            ->whereDoesntHave('enabledMonitorApis', fn (Builder $apis) => $apis->where('current_status', 'warning'));
    }

    protected static function whereHasHealthySurface(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereHas('activeComponents', fn (Builder $components) => $components
                    ->where('current_status', 'healthy')
                    ->where('is_stale', false)
                    ->whereNotNull('last_heartbeat_at'))
                ->orWhereHas('monitoredWebsites', fn (Builder $websites) => $websites
                    ->where('current_status', 'healthy')
                    ->whereNull('stale_at')
                    ->whereNotNull('last_heartbeat_at'))
                ->orWhereHas('enabledMonitorApis', fn (Builder $apis) => $apis
                    ->where('current_status', 'healthy')
                    ->whereNull('stale_at')
                    ->whereNotNull('last_heartbeat_at'));
        });
    }

    protected static function whereHasUnknownApplicationStatus(Builder $query): Builder
    {
        return static::whereDoesntHaveMonitoredStatus($query, ['warning', 'danger'])
            ->where(function (Builder $query): void {
                static::whereHasUnknownSurface($query)
                    ->orWhere(function (Builder $query): void {
                        static::whereDoesntHaveTrackedSurface($query);
                    });
            });
    }

    protected static function whereHasUnknownSurface(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereHas('activeComponents', fn (Builder $components) => $components
                    ->where('is_stale', false)
                    ->where(function (Builder $components): void {
                        $components
                            ->whereNull('current_status')
                            ->orWhereNotIn('current_status', Project::KNOWN_APPLICATION_STATUSES)
                            ->orWhere(function (Builder $components): void {
                                $components
                                    ->where('current_status', 'healthy')
                                    ->whereNull('last_heartbeat_at');
                            });
                    }))
                ->orWhereHas('monitoredWebsites', fn (Builder $websites) => $websites
                    ->whereNull('stale_at')
                    ->where(function (Builder $websites): void {
                        $websites
                            ->whereNull('current_status')
                            ->orWhereNotIn('current_status', Project::KNOWN_APPLICATION_STATUSES)
                            ->orWhere(function (Builder $websites): void {
                                $websites
                                    ->where('current_status', 'healthy')
                                    ->whereNull('last_heartbeat_at');
                            });
                    }))
                ->orWhereHas('enabledMonitorApis', fn (Builder $apis) => $apis
                    ->whereNull('stale_at')
                    ->where(function (Builder $apis): void {
                        $apis
                            ->whereNull('current_status')
                            ->orWhereNotIn('current_status', Project::KNOWN_APPLICATION_STATUSES)
                            ->orWhere(function (Builder $apis): void {
                                $apis
                                    ->where('current_status', 'healthy')
                                    ->whereNull('last_heartbeat_at');
                            });
                    }));
        });
    }

    protected static function whereDoesntHaveUnknownSurface(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('activeComponents', fn (Builder $components) => $components
                ->where('is_stale', false)
                ->where(function (Builder $components): void {
                    $components
                        ->whereNull('current_status')
                        ->orWhereNotIn('current_status', Project::KNOWN_APPLICATION_STATUSES)
                        ->orWhere(function (Builder $components): void {
                            $components
                                ->where('current_status', 'healthy')
                                ->whereNull('last_heartbeat_at');
                        });
                }))
            ->whereDoesntHave('monitoredWebsites', fn (Builder $websites) => $websites
                ->whereNull('stale_at')
                ->where(function (Builder $websites): void {
                    $websites
                        ->whereNull('current_status')
                        ->orWhereNotIn('current_status', Project::KNOWN_APPLICATION_STATUSES)
                        ->orWhere(function (Builder $websites): void {
                            $websites
                                ->where('current_status', 'healthy')
                                ->whereNull('last_heartbeat_at');
                        });
                }))
            ->whereDoesntHave('enabledMonitorApis', fn (Builder $apis) => $apis
                ->whereNull('stale_at')
                ->where(function (Builder $apis): void {
                    $apis
                        ->whereNull('current_status')
                        ->orWhereNotIn('current_status', Project::KNOWN_APPLICATION_STATUSES)
                        ->orWhere(function (Builder $apis): void {
                            $apis
                                ->where('current_status', 'healthy')
                                ->whereNull('last_heartbeat_at');
                        });
                }));
    }

    protected static function whereDoesntHaveTrackedSurface(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('activeComponents')
            ->whereDoesntHave('monitoredWebsites')
            ->whereDoesntHave('enabledMonitorApis');
    }

    /**
     * Gate the project cascade behind update permissions for every child resource it mutates.
     *
     * The cascade touches Website, MonitorApis, and ProjectComponent rows directly, so
     * a project-only update permission is not sufficient authorization by itself.
     */
    protected static function userCanCascadeMonitoring(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return $user->can('Update:Project')
            && $user->can('Update:Website')
            && $user->can('Update:MonitorApis')
            && $user->can('Update:ProjectComponent');
    }

    /**
     * Cascade enable/disable state to every monitored child of the selected projects.
     *
     * @param  Collection<int, Project>  $projects
     * @return array{websites:int, apis:int, components:int}
     */
    protected static function cascadeProjectState(Collection $projects, bool $enable): array
    {
        $projectIds = $projects->pluck('id')->all();

        if (empty($projectIds)) {
            return ['websites' => 0, 'apis' => 0, 'components' => 0];
        }

        return DB::transaction(function () use ($projectIds, $enable): array {
            $websitesChanged = static::cascadeWebsiteChecks($projectIds, $enable);

            $apisChanged = MonitorApis::query()
                ->whereIn('project_id', $projectIds)
                ->where('is_enabled', ! $enable)
                ->update(['is_enabled' => $enable]);

            $componentsChanged = ProjectComponent::query()
                ->whereIn('project_id', $projectIds)
                ->where('is_archived', $enable)
                ->update([
                    'is_archived' => ! $enable,
                    'archived_at' => $enable ? null : now(),
                ]);

            return [
                'websites' => $websitesChanged,
                'apis' => $apisChanged,
                'components' => $componentsChanged,
            ];
        });
    }

    /**
     * Pause or resume website checks while preserving which check types should resume.
     *
     * @param  array<int, int>  $projectIds
     * @param  bool  $enable  Whether to resume (true) or pause (false) website checks.
     * @return int Number of website rows changed.
     */
    protected static function cascadeWebsiteChecks(array $projectIds, bool $enable): int
    {
        if ($enable) {
            $websitesChanged = Website::query()
                ->whereIn('project_id', $projectIds)
                ->where(function (Builder $query): void {
                    $query
                        ->where('project_paused_uptime_check', true)
                        ->orWhere('project_paused_ssl_check', true)
                        ->orWhere('project_paused_outbound_check', true)
                        ->orWhere(function (Builder $query): void {
                            $query
                                ->where('uptime_check', false)
                                ->where('ssl_check', false)
                                ->where('project_paused_uptime_check', false)
                                ->where('project_paused_ssl_check', false)
                                ->where('project_paused_outbound_check', false);
                        });
                })
                ->count();

            Website::query()
                ->whereIn('project_id', $projectIds)
                ->where('uptime_check', false)
                ->where('ssl_check', false)
                ->where('project_paused_uptime_check', false)
                ->where('project_paused_ssl_check', false)
                ->where('project_paused_outbound_check', false)
                ->update([
                    'uptime_check' => true,
                    'ssl_check' => true,
                ]);

            Website::query()
                ->whereIn('project_id', $projectIds)
                ->where('project_paused_uptime_check', true)
                ->update([
                    'uptime_check' => true,
                    'project_paused_uptime_check' => false,
                ]);

            Website::query()
                ->whereIn('project_id', $projectIds)
                ->where('project_paused_ssl_check', true)
                ->update([
                    'ssl_check' => true,
                    'project_paused_ssl_check' => false,
                ]);

            Website::query()
                ->whereIn('project_id', $projectIds)
                ->where('project_paused_outbound_check', true)
                ->update([
                    'outbound_check' => true,
                    'project_paused_outbound_check' => false,
                ]);

            return $websitesChanged;
        }

        $websitesChanged = Website::query()
            ->whereIn('project_id', $projectIds)
            ->where(function (Builder $query): void {
                $query
                    ->where('uptime_check', true)
                    ->orWhere('ssl_check', true)
                    ->orWhere('outbound_check', true);
            })
            ->count();

        Website::query()
            ->whereIn('project_id', $projectIds)
            ->where('uptime_check', true)
            ->update([
                'uptime_check' => false,
                'project_paused_uptime_check' => true,
            ]);

        Website::query()
            ->whereIn('project_id', $projectIds)
            ->where('ssl_check', true)
            ->update([
                'ssl_check' => false,
                'project_paused_ssl_check' => true,
            ]);

        Website::query()
            ->whereIn('project_id', $projectIds)
            ->where('outbound_check', true)
            ->update([
                'outbound_check' => false,
                'project_paused_outbound_check' => true,
            ]);

        return $websitesChanged;
    }

    protected static function summaryTitle(int $projectCount, bool $enable, int $totalChanged): string
    {
        if ($totalChanged === 0) {
            return $enable ? 'Nothing to enable' : 'Nothing to disable';
        }

        $verb = $enable ? 'Monitoring enabled' : 'Monitoring paused';

        $subject = $projectCount === 1
            ? '1 application'
            : "{$projectCount} applications";

        return "{$verb} for {$subject}";
    }

    /**
     * @param  array{websites:int, apis:int, components:int}  $summary
     */
    protected static function summaryBody(array $summary, bool $enable): string
    {
        $totalChanged = $summary['websites'] + $summary['apis'] + $summary['components'];

        if ($totalChanged === 0) {
            return $enable
                ? 'All monitored resources for the selected applications were already enabled.'
                : 'All monitored resources for the selected applications were already paused.';
        }

        $parts = [];
        if ($summary['websites']) {
            $parts[] = $summary['websites'].' '.($summary['websites'] === 1 ? 'website' : 'websites');
        }
        if ($summary['apis']) {
            $parts[] = $summary['apis'].' '.($summary['apis'] === 1 ? 'API monitor' : 'API monitors');
        }
        if ($summary['components']) {
            $parts[] = $summary['components'].' '.($summary['components'] === 1 ? 'component' : 'components');
        }

        return ($enable ? 'Resumed checks for ' : 'Paused checks for ').implode(', ', $parts).'.';
    }
}
