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
use Filament\Tables\Table;
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
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
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
                        ->modalDescription('All websites and API monitors tied to these applications will resume scheduled checks. Archived components will be un-archived and start accepting heartbeats again.')
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
                        ->modalDescription('Scheduled uptime checks, API checks, and heartbeat tracking will pause for every website, API monitor, and component in the selected applications. Use this during maintenance windows. History and configuration are preserved.')
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
            $websitesChanged = Website::query()
                ->whereIn('project_id', $projectIds)
                ->where('uptime_check', ! $enable)
                ->update(['uptime_check' => $enable]);

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
