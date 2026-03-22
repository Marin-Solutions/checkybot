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
     * @param  array{declared_components?: array<int, array<string, mixed>>, components?: array<int, array<string, mixed>>}  $payload
     * @return array<string, array<string, int>>
     */
    public function sync(Project $project, array $payload): array
    {
        $declaredComponents = $payload['declared_components'] ?? [];
        $heartbeats = $payload['components'] ?? [];

        return DB::transaction(function () use ($project, $declaredComponents, $heartbeats): array {
            $createdNames = [];
            $updatedNames = [];
            $recordedHeartbeats = 0;
            $activeNames = [];

            foreach ($declaredComponents as $declaration) {
                $activeNames[] = $declaration['name'];

                $component = ProjectComponent::query()
                    ->where('project_id', $project->id)
                    ->where('name', $declaration['name'])
                    ->first();

                $attributes = [
                    'project_id' => $project->id,
                    'name' => $declaration['name'],
                    'source' => 'package',
                    'is_archived' => false,
                    'archived_at' => null,
                    'declared_interval' => $declaration['interval'],
                    'interval_minutes' => IntervalParser::toMinutes($declaration['interval']),
                    'created_by' => $project->created_by,
                ];

                if ($component) {
                    $component->fill($attributes);

                    if (! $component->isDirty()) {
                        continue;
                    }

                    $component->save();

                    if (! in_array($component->name, $createdNames, true) && ! in_array($component->name, $updatedNames, true)) {
                        $updatedNames[] = $component->name;
                    }
                } else {
                    ProjectComponent::create($attributes + [
                        'current_status' => 'healthy',
                        'last_reported_status' => 'healthy',
                        'metrics' => [],
                    ]);
                    $createdNames[] = $declaration['name'];
                }
            }

            foreach ($heartbeats as $payload) {
                $component = ProjectComponent::query()
                    ->where('project_id', $project->id)
                    ->where('name', $payload['name'])
                    ->firstOrFail();

                $previousStatus = $component->current_status;

                $component->fill([
                    'summary' => $payload['summary'] ?? null,
                    'current_status' => $payload['status'],
                    'last_reported_status' => $payload['status'],
                    'metrics' => $payload['metrics'] ?? [],
                    'last_heartbeat_at' => $payload['observed_at'],
                    'stale_detected_at' => null,
                    'is_stale' => false,
                    'is_archived' => false,
                    'archived_at' => null,
                    'declared_interval' => $payload['interval'],
                    'interval_minutes' => IntervalParser::toMinutes($payload['interval']),
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

        return $query->update([
            'is_archived' => true,
            'archived_at' => now(),
        ]);
    }
}
