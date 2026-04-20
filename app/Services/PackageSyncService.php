<?php

namespace App\Services;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PackageSyncService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{project: Project, project_created: bool, summary: array<string, int>, synced_at: Carbon}
     */
    public function sync(User $user, array $payload): array
    {
        return DB::transaction(function () use ($user, $payload): array {
            $syncedAt = now();
            $project = $this->upsertProject($user, $payload, $syncedAt);
            $summary = $this->syncApiChecks($project, $payload, $syncedAt);

            return [
                'project' => $project,
                'project_created' => $project->wasRecentlyCreated,
                'summary' => $summary,
                'synced_at' => $syncedAt,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertProject(User $user, array $payload, Carbon $syncedAt): Project
    {
        $projectData = $payload['project'];

        $project = Project::query()
            ->where('created_by', $user->id)
            ->where('environment', $projectData['environment'])
            ->where('package_key', $projectData['key'])
            ->lockForUpdate()
            ->first();

        if (! $project instanceof Project) {
            $project = Project::query()
                ->where('created_by', $user->id)
                ->where('environment', $projectData['environment'])
                ->where('identity_endpoint', $projectData['base_url'])
                ->lockForUpdate()
                ->first();
        }

        if (! $project instanceof Project) {
            $project = new Project([
                'created_by' => $user->id,
                'token' => hash('sha256', (string) Str::uuid()),
            ]);
        }

        $project->fill([
            'package_key' => $projectData['key'],
            'name' => $projectData['name'],
            'environment' => $projectData['environment'],
            'identity_endpoint' => $projectData['base_url'],
            'base_url' => $projectData['base_url'],
            'repository' => $projectData['repository'] ?? null,
            'sync_defaults' => $this->redactDefaults($payload['defaults'] ?? []),
            'last_synced_at' => $syncedAt,
        ]);

        $project->save();

        return $project;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{created: int, updated: int, disabled_missing: int, unsupported: int}
     */
    private function syncApiChecks(Project $project, array $payload, Carbon $syncedAt): array
    {
        $created = 0;
        $updated = 0;
        $unsupported = 0;
        $activeApiKeys = [];
        $defaults = $payload['defaults'] ?? [];

        foreach ($payload['checks'] as $check) {
            if ($check['type'] !== 'api') {
                $unsupported++;

                continue;
            }

            $activeApiKeys[] = $check['key'];
            $monitorApi = MonitorApis::withTrashed()
                ->where('project_id', $project->id)
                ->where('source', 'package')
                ->where('package_name', $check['key'])
                ->first();

            $wasCreated = false;

            if (! $monitorApi instanceof MonitorApis) {
                $monitorApi = new MonitorApis;
                $wasCreated = true;
            } elseif ($monitorApi->trashed()) {
                $monitorApi->restore();
            }

            $monitorApi->fill([
                'project_id' => $project->id,
                'created_by' => $project->created_by,
                'title' => $check['name'],
                'url' => $this->resolveUrl($project->base_url, $check['url']),
                'http_method' => strtoupper($check['method'] ?? 'GET'),
                'request_path' => $check['url'],
                'data_path' => $this->primaryDataPath($check['assertions'] ?? []),
                'headers' => array_replace($defaults['headers'] ?? [], $check['headers'] ?? []),
                'expected_status' => $check['expected_status'] ?? 200,
                'timeout_seconds' => $check['timeout_seconds'] ?? ($defaults['timeout_seconds'] ?? null),
                'package_schedule' => $check['schedule'] ?? null,
                // Existing stale-check logic still reads package_interval; package_schedule preserves the v1 contract name.
                'package_interval' => $check['schedule'] ?? null,
                'is_enabled' => $check['enabled'] ?? true,
                'source' => 'package',
                'package_name' => $check['key'],
                'last_synced_at' => $syncedAt,
            ]);
            $monitorApi->save();

            $this->syncAssertions($monitorApi, $check['assertions'] ?? []);

            if ($wasCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        $disabledMissing = $this->disableMissingApiChecks($project, $activeApiKeys, $syncedAt);

        return [
            'created' => $created,
            'updated' => $updated,
            'disabled_missing' => $disabledMissing,
            'unsupported' => $unsupported,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $assertions
     */
    private function syncAssertions(MonitorApis $monitorApi, array $assertions): void
    {
        MonitorApiAssertion::query()
            ->where('monitor_api_id', $monitorApi->id)
            ->delete();

        if ($assertions === []) {
            return;
        }

        $timestamp = now();

        MonitorApiAssertion::query()->insert(
            collect($assertions)
                ->values()
                ->map(fn (array $assertion, int $index): array => [
                    'monitor_api_id' => $monitorApi->id,
                    'data_path' => $this->normalizeJsonPath($assertion['path']),
                    'assertion_type' => $this->mapAssertionType($assertion['type']),
                    'expected_type' => $assertion['expected_type'] ?? null,
                    'comparison_operator' => $assertion['comparison_operator'] ?? (
                        $assertion['type'] === 'json_path_equals' ? '=' : null
                    ),
                    'expected_value' => array_key_exists('expected_value', $assertion)
                        ? (string) $assertion['expected_value']
                        : null,
                    'regex_pattern' => $assertion['regex_pattern'] ?? null,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->all()
        );
    }

    /**
     * @param  array<int, string>  $activeApiKeys
     */
    private function disableMissingApiChecks(Project $project, array $activeApiKeys, Carbon $syncedAt): int
    {
        $query = MonitorApis::query()
            ->where('project_id', $project->id)
            ->where('source', 'package')
            ->where('is_enabled', true);

        if ($activeApiKeys !== []) {
            $query->whereNotIn('package_name', array_values(array_unique($activeApiKeys)));
        }

        return $query->update([
            'is_enabled' => false,
            'current_status' => 'unknown',
            'status_summary' => 'Disabled because it was missing from the latest package sync.',
            'last_synced_at' => $syncedAt,
        ]);
    }

    private function resolveUrl(?string $baseUrl, string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return rtrim((string) $baseUrl, '/').'/'.ltrim($url, '/');
    }

    /**
     * @param  array<int, array<string, mixed>>  $assertions
     */
    private function primaryDataPath(array $assertions): string
    {
        $path = Arr::first($assertions, fn (array $assertion): bool => isset($assertion['path']))['path'] ?? '';

        return $path === '' ? '' : $this->normalizeJsonPath($path);
    }

    private function normalizeJsonPath(string $path): string
    {
        return Str::of($path)
            ->replaceStart('$.', '')
            ->replaceStart('$', '')
            ->ltrim('.')
            ->toString();
    }

    private function mapAssertionType(string $type): string
    {
        return match ($type) {
            'json_path_exists' => 'exists',
            'json_path_not_exists' => 'not_exists',
            'json_path_equals' => 'value_compare',
            default => $type,
        };
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function redactDefaults(array $defaults): array
    {
        if (! isset($defaults['headers']) || ! is_array($defaults['headers'])) {
            return $defaults;
        }

        $defaults['headers'] = collect($defaults['headers'])
            ->map(fn (): string => '[redacted]')
            ->all();

        return $defaults;
    }
}
