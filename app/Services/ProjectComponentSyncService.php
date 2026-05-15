<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectComponent;
use Illuminate\Support\Facades\DB;

class ProjectComponentSyncService
{
    public const MISSING_PACKAGE_SYNC_SUMMARY = 'Disabled because it was missing from the latest package sync.';

    /**
     * @param  array{full_manifest?: bool, declared_components?: array<int, array<string, mixed>>, components?: array<int, array<string, mixed>>}  $payload
     * @return array<string, array<string, int>>
     */
    public function sync(Project $project, array $payload): array
    {
        $declaredComponents = $payload['declared_components'] ?? [];
        $isFullManifest = array_key_exists('declared_components', $payload)
            && filter_var($payload['full_manifest'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $declaredNames = array_values(array_unique(array_column($declaredComponents, 'name')));
        $componentNames = $declaredNames;

        return DB::transaction(function () use ($project, $declaredComponents, $componentNames, $isFullManifest): array {
            $createdNames = [];
            $updatedNames = [];
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
                        'summary' => 'Awaiting active child check results',
                        'metrics' => [],
                    ]);
                    $componentsByName->put($component->name, $component);
                    $createdNames[] = $declaration['name'];
                }
            }

            $archived = $isFullManifest
                ? $this->archiveMissingComponents($project, $componentNames)
                : 0;

            $summary = [
                'components' => [
                    'created' => count($createdNames),
                    'updated' => count($updatedNames),
                    'archived' => $archived,
                ],
            ];

            $project->forceFill([
                'last_component_synced_at' => now(),
                'latest_component_sync_summary' => $summary,
            ])->save();

            return $summary;
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

        return $query->update(ProjectComponent::disabledHealthAttributes(self::MISSING_PACKAGE_SYNC_SUMMARY) + [
            'is_archived' => true,
            'archived_at' => now(),
            'archive_reason' => ProjectComponent::ARCHIVE_REASON_PACKAGE,
        ]);
    }
}
