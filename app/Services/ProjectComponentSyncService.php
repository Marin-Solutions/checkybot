<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectComponent;
use Illuminate\Support\Facades\DB;

class ProjectComponentSyncService
{
    public function __construct(
        protected ProjectComponentNotificationService $projectComponentNotificationService
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, array<string, int>>
     */
    public function sync(Project $project, array $components): array
    {
        return DB::transaction(function () use ($project, $components): array {
            $created = 0;
            $updated = 0;
            $recordedHeartbeats = 0;
            $activeNames = [];

            foreach ($components as $payload) {
                $activeNames[] = $payload['name'];

                $component = ProjectComponent::query()
                    ->where('project_id', $project->id)
                    ->where('name', $payload['name'])
                    ->first();

                $attributes = [
                    'project_id' => $project->id,
                    'name' => $payload['name'],
                    'summary' => $payload['summary'] ?? null,
                    'source' => 'package',
                    'declared_interval' => $payload['interval'],
                    'interval_minutes' => IntervalParser::toMinutes($payload['interval']),
                    'current_status' => $payload['status'],
                    'last_reported_status' => $payload['status'],
                    'metrics' => $payload['metrics'] ?? [],
                    'last_heartbeat_at' => $payload['observed_at'],
                    'stale_detected_at' => null,
                    'is_stale' => false,
                    'is_archived' => false,
                    'archived_at' => null,
                    'created_by' => $project->created_by,
                ];

                if ($component) {
                    $previousStatus = $component->current_status;
                    $component->update($attributes);
                    $updated++;
                } else {
                    $component = ProjectComponent::create($attributes);
                    $previousStatus = null;
                    $created++;
                }

                $component->heartbeats()->create([
                    'component_name' => $component->name,
                    'status' => $payload['status'],
                    'event' => 'heartbeat',
                    'summary' => $payload['summary'] ?? null,
                    'metrics' => $payload['metrics'] ?? [],
                    'observed_at' => $payload['observed_at'],
                ]);

                $recordedHeartbeats++;

                if (
                    in_array($payload['status'], ['warning', 'danger'], true)
                    && $previousStatus !== $payload['status']
                ) {
                    $this->projectComponentNotificationService->notify(
                        $component->loadMissing('project'),
                        'heartbeat',
                        $payload['status']
                    );
                }
            }

            $archived = $this->archiveMissingComponents($project, $activeNames);

            return [
                'components' => [
                    'created' => $created,
                    'updated' => $updated,
                    'archived' => $archived,
                ],
                'heartbeats' => [
                    'recorded' => $recordedHeartbeats,
                ],
            ];
        });
    }

    /**
     * @param  array<int, string>  $activeNames
     */
    private function archiveMissingComponents(Project $project, array $activeNames): int
    {
        $query = ProjectComponent::query()
            ->where('project_id', $project->id)
            ->where('is_archived', false);

        if ($activeNames !== []) {
            $query->whereNotIn('name', $activeNames);
        }

        return $query->update([
            'is_archived' => true,
            'archived_at' => now(),
        ]);
    }
}
