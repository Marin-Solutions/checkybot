<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectComponent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProjectComponentSyncService
{
    public const MISSING_PACKAGE_SYNC_SUMMARY = 'Disabled because it was missing from the latest package sync.';

    public function __construct(
        protected ProjectComponentNotificationService $projectComponentNotificationService
    ) {}

    /**
     * @param  array{full_manifest?: bool, declared_components?: array<int, array<string, mixed>>, components?: array<int, array<string, mixed>>}  $payload
     * @return array<string, array<string, int>>
     */
    public function sync(Project $project, array $payload): array
    {
        $declaredComponents = $payload['declared_components'] ?? [];
        $heartbeats = $payload['components'] ?? [];
        $isFullManifest = array_key_exists('declared_components', $payload)
            && filter_var($payload['full_manifest'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $declaredNames = array_values(array_unique(array_column($declaredComponents, 'name')));
        $heartbeatNames = array_values(array_unique(array_column($heartbeats, 'name')));
        $componentNames = array_values(array_unique(array_merge($declaredNames, $heartbeatNames)));

        return DB::transaction(function () use ($project, $declaredComponents, $heartbeats, $componentNames, $isFullManifest): array {
            $createdNames = [];
            $updatedNames = [];
            $recordedHeartbeats = 0;
            $componentsByName = collect();

            if ($componentNames !== []) {
                $componentsByName = ProjectComponent::query()
                    ->where('project_id', $project->id)
                    ->whereIn('name', $componentNames)
                    ->get()
                    ->keyBy('name');
            }

            foreach ($declaredComponents as $declaration) {
                $component = $componentsByName->get($declaration['name']);

                $attributes = [
                    'project_id' => $project->id,
                    'name' => $declaration['name'],
                    'source' => 'package',
                    'declared_interval' => $declaration['interval'],
                    'interval_minutes' => IntervalParser::toMinutes($declaration['interval']),
                    'created_by' => $project->created_by,
                ];

                if ($component) {
                    if ($component->archive_reason === ProjectComponent::ARCHIVE_REASON_PACKAGE) {
                        $attributes['is_archived'] = false;
                        $attributes['archived_at'] = null;
                        $attributes['archive_reason'] = null;
                    }

                    $component->fill($attributes);

                    if (! $component->isDirty()) {
                        continue;
                    }

                    $component->save();

                    if (! in_array($component->name, $createdNames, true) && ! in_array($component->name, $updatedNames, true)) {
                        $updatedNames[] = $component->name;
                    }
                } else {
                    $component = ProjectComponent::create($attributes + [
                        'is_archived' => false,
                        'archived_at' => null,
                        'archive_reason' => null,
                        'current_status' => 'unknown',
                        'last_reported_status' => 'unknown',
                        'summary' => 'Awaiting first heartbeat',
                        'metrics' => [],
                    ]);
                    $componentsByName->put($component->name, $component);
                    $createdNames[] = $declaration['name'];
                }
            }

            foreach ($heartbeats as $heartbeat) {
                $component = $componentsByName->get($heartbeat['name']);

                if (! $component) {
                    $component = ProjectComponent::create([
                        'project_id' => $project->id,
                        'name' => $heartbeat['name'],
                        'source' => 'package',
                        'is_archived' => false,
                        'archived_at' => null,
                        'archive_reason' => null,
                        'declared_interval' => $heartbeat['interval'],
                        'interval_minutes' => IntervalParser::toMinutes($heartbeat['interval']),
                        'current_status' => 'unknown',
                        'last_reported_status' => 'unknown',
                        'summary' => 'Awaiting first heartbeat',
                        'metrics' => [],
                        'created_by' => $project->created_by,
                    ]);

                    $componentsByName->put($component->name, $component);
                    $createdNames[] = $component->name;
                }

                if ($component->is_archived && $component->archive_reason !== ProjectComponent::ARCHIVE_REASON_PACKAGE) {
                    continue;
                }

                $isLiveHeartbeat = $this->isNewerHeartbeat($component, $heartbeat['observed_at']);

                if ($isLiveHeartbeat) {
                    $previousStatus = $component->current_status;
                    $wasStale = $component->is_stale;

                    $component->fill([
                        'summary' => $heartbeat['summary'] ?? null,
                        'current_status' => $heartbeat['status'],
                        'last_reported_status' => $heartbeat['status'],
                        'metrics' => $heartbeat['metrics'] ?? [],
                        'last_heartbeat_at' => $heartbeat['observed_at'],
                        'stale_detected_at' => null,
                        'is_stale' => false,
                        'is_archived' => false,
                        'archived_at' => null,
                        'archive_reason' => null,
                        'declared_interval' => $heartbeat['interval'],
                        'interval_minutes' => IntervalParser::toMinutes($heartbeat['interval']),
                    ]);

                    if ($component->isDirty()) {
                        $component->save();
                    }

                    if (
                        ! in_array($component->name, $createdNames, true)
                        && ! in_array($component->name, $updatedNames, true)
                    ) {
                        $updatedNames[] = $component->name;
                    }
                }

                $component->heartbeats()->create([
                    'component_name' => $component->name,
                    'status' => $heartbeat['status'],
                    'event' => 'heartbeat',
                    'summary' => $heartbeat['summary'] ?? null,
                    'metrics' => $heartbeat['metrics'] ?? [],
                    'observed_at' => $heartbeat['observed_at'],
                ]);

                $recordedHeartbeats++;

                if ($isLiveHeartbeat) {
                    if (
                        in_array($heartbeat['status'], ['warning', 'danger'], true)
                        && $previousStatus !== $heartbeat['status']
                    ) {
                        $this->projectComponentNotificationService->notify(
                            $component->loadMissing('project'),
                            'heartbeat',
                            $heartbeat['status']
                        );
                    } elseif (
                        $heartbeat['status'] === 'healthy'
                        && (
                            in_array($previousStatus, ['warning', 'danger'], true)
                            || $wasStale
                        )
                    ) {
                        $this->projectComponentNotificationService->notify(
                            $component->loadMissing('project'),
                            'recovered',
                            $heartbeat['status']
                        );
                    }
                }
            }

            $archived = $isFullManifest
                ? $this->archiveMissingComponents($project, $componentNames)
                : 0;

            return [
                'components' => [
                    'created' => count($createdNames),
                    'updated' => count($updatedNames),
                    'archived' => $archived,
                ],
                'heartbeats' => [
                    'recorded' => $recordedHeartbeats,
                ],
            ];
        });
    }

    private function isNewerHeartbeat(ProjectComponent $component, mixed $observedAt): bool
    {
        if ($component->last_heartbeat_at === null) {
            return true;
        }

        return Carbon::parse($observedAt)->gt($component->last_heartbeat_at);
    }

    /**
     * @param  array<int, string>  $activeNames
     */
    private function archiveMissingComponents(Project $project, array $activeNames): int
    {
        $query = ProjectComponent::query()
            ->where('project_id', $project->id)
            ->where('source', 'package')
            ->where('is_archived', false);

        if ($activeNames !== []) {
            $query->whereNotIn('name', $activeNames);
        }

        return $query->update(ProjectComponent::disabledHealthAttributes(self::MISSING_PACKAGE_SYNC_SUMMARY) + [
            'is_archived' => true,
            'archived_at' => now(),
            'archive_reason' => ProjectComponent::ARCHIVE_REASON_PACKAGE,
        ]);
    }
}
