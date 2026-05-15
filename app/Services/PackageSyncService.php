<?php

namespace App\Services;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PackageSyncService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{project: Project, project_created: bool, summary: array<string, mixed>, synced_at: Carbon}
     */
    public function sync(User $user, array $payload): array
    {
        return DB::transaction(function () use ($user, $payload): array {
            $syncedAt = now();
            $project = $this->upsertProject($user, $payload, $syncedAt);
            $apiSummary = $this->syncApiChecks($project, $payload, $syncedAt);
            $uptimeSummary = $this->syncWebsiteChecks($project, $payload, 'uptime', $syncedAt);
            $sslSummary = $this->syncWebsiteChecks($project, $payload, 'ssl', $syncedAt);
            $this->recalculateUptimeOnlyPackageIntervals($project);
            $this->resetStatusForFullyDisabledWebsites($project);
            $summary = $this->summarize($apiSummary, $uptimeSummary, $sslSummary);

            $project->forceFill([
                'latest_package_sync_summary' => $summary,
            ])->save();

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
     * @return array{created: int, updated: int, disabled_missing: int}
     */
    private function syncApiChecks(Project $project, array $payload, Carbon $syncedAt): array
    {
        $created = 0;
        $updated = 0;
        $activeApiKeys = [];
        $defaults = $payload['defaults'] ?? [];

        foreach ($payload['checks'] as $check) {
            if ($check['type'] !== 'api') {
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

            $schedule = $this->apiSchedule($check['schedule'] ?? null);
            $normalizedSchedule = IntervalParser::normalizeOrFail($schedule, 'schedule');
            $data = [
                'project_id' => $project->id,
                'project_component_id' => $this->projectComponentIdFor($project, $check['component'] ?? null),
                'created_by' => $project->created_by,
                'title' => $check['name'],
                'url' => $this->resolveUrl($project->base_url, $check['url']),
                'http_method' => strtoupper($check['method'] ?? 'GET'),
                'request_path' => $check['url'],
                'data_path' => $this->primaryDataPath($check['assertions'] ?? []),
                'headers' => $this->mergeHeaders($defaults['headers'] ?? [], $check['headers'] ?? []),
                'request_body_type' => $check['request_body_type'] ?? null,
                'request_body' => $check['request_body'] ?? null,
                'expected_status' => $check['expected_status'] ?? 200,
                'timeout_seconds' => $check['timeout_seconds'] ?? ($defaults['timeout_seconds'] ?? null),
                'save_failed_response' => $check['save_failed_response'] ?? true,
                'package_schedule' => $schedule,
                // Stale detection still reads package_interval, so persist the compact normalized form there.
                'package_interval' => $normalizedSchedule,
                'is_enabled' => $check['enabled'] ?? true,
                'source' => 'package',
                'package_name' => $check['key'],
                'last_synced_at' => $syncedAt,
            ];

            $configChanged = $wasCreated
                || $monitorApi->wasChanged('deleted_at')
                || $this->apiConfigurationChanged($monitorApi, $data);
            $assertionsChanged = ! $wasCreated
                && $this->apiAssertionsChanged($monitorApi, $check['assertions'] ?? []);

            if ($wasCreated || $configChanged || $assertionsChanged) {
                $data += $this->pendingLiveHealthAttributes();
            }

            $monitorApi->fill($data);
            $monitorApi->save();

            if ($wasCreated || $assertionsChanged) {
                $this->syncAssertions($monitorApi, $check['assertions'] ?? []);
            }

            if ($wasCreated) {
                $created++;
            } elseif ($configChanged || $assertionsChanged) {
                $updated++;
            }
        }

        $disabledMissing = $this->disableMissingApiChecks($project, $activeApiKeys, $syncedAt);

        return [
            'created' => $created,
            'updated' => $updated,
            'disabled_missing' => $disabledMissing,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{created: int, updated: int, disabled_missing: int}
     */
    private function syncWebsiteChecks(Project $project, array $payload, string $type, Carbon $syncedAt): array
    {
        $created = 0;
        $updated = 0;
        $activeKeys = [];

        foreach ($payload['checks'] as $check) {
            if ($check['type'] !== $type) {
                continue;
            }

            $activeKeys[] = $check['key'];
            $website = Website::withTrashed()
                ->where('project_id', $project->id)
                ->where('source', 'package')
                ->where('package_name', $check['key'])
                ->first();

            $wasCreated = false;
            $wasRestored = false;
            $wasTypeEnabled = $website instanceof Website
                && ! $website->trashed()
                && (bool) ($type === 'uptime' ? $website->uptime_check : $website->ssl_check);

            if (! $website instanceof Website) {
                $website = new Website;
                $wasCreated = true;
            } elseif ($website->trashed()) {
                $website->restore();
                $wasRestored = true;
            }

            $normalizedSchedule = IntervalParser::normalizeOrFail($check['schedule'] ?? null, 'schedule');

            $enabled = $check['enabled'] ?? true;
            $data = [
                'project_id' => $project->id,
                'project_component_id' => $this->projectComponentIdFor($project, $check['component'] ?? null),
                'created_by' => $project->created_by,
                'name' => $check['name'],
                'url' => $this->resolveUrl($project->base_url, $check['url']),
                'description' => '',
                'source' => 'package',
                'package_name' => $check['key'],
                'last_synced_at' => $syncedAt,
            ];

            if ($type === 'uptime') {
                $data['uptime_check'] = $enabled;
                $data['uptime_interval'] = IntervalParser::toMinutes($normalizedSchedule);
                $data['package_interval'] = IntervalParser::fromMinutes($data['uptime_interval']);
                // Don't inherit ssl_check from a restored row — the SSL pass will set it if needed.
                $data['ssl_check'] = ($website->exists && ! $wasRestored) ? $website->ssl_check : false;
            } else {
                $data['ssl_check'] = $enabled;
                $data['package_interval'] = $normalizedSchedule;
                // Don't inherit uptime_check from a restored row — the uptime pass already ran and didn't include this key.
                $data['uptime_check'] = ($website->exists && ! $wasRestored) ? $website->uptime_check : false;
                $data['uptime_interval'] = $data['uptime_check'] ? $website->uptime_interval : null;

                if ($data['uptime_check']) {
                    $data['package_interval'] = IntervalParser::fromMinutes((int) $data['uptime_interval']);
                }
            }

            $configChanged = $wasCreated
                || $website->wasChanged('deleted_at')
                || $this->websiteConfigurationChanged($website, $data);

            if ($wasCreated || $wasRestored || $configChanged) {
                $data += $this->pendingLiveHealthAttributes();
            }

            $website->fill($data);
            $website->save();

            if ($wasCreated || ($enabled && ! $wasRestored && ! $wasTypeEnabled)) {
                $created++;
            } elseif ($wasRestored || $configChanged) {
                $updated++;
            }
        }

        $disabledMissing = $this->disableMissingWebsiteChecks($project, $activeKeys, $type, $syncedAt);

        return [
            'created' => $created,
            'updated' => $updated,
            'disabled_missing' => $disabledMissing,
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
            collect($this->incomingAssertions($assertions))
                ->map(fn (array $assertion): array => $assertion + [
                    'monitor_api_id' => $monitorApi->id,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->all()
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apiConfigurationChanged(MonitorApis $monitorApi, array $data): bool
    {
        foreach ($data as $field => $value) {
            if ($field === 'last_synced_at') {
                continue;
            }

            $current = match ($field) {
                'headers' => $monitorApi->headers,
                'request_body' => $this->normalizeRequestBodyForComparison($monitorApi->request_body),
                default => $monitorApi->{$field},
            };

            $incoming = $field === 'request_body'
                ? $this->normalizeRequestBodyForComparison($value)
                : $value;

            if ($current != $incoming) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $assertions
     */
    private function apiAssertionsChanged(MonitorApis $monitorApi, array $assertions): bool
    {
        return $this->storedAssertions($monitorApi) !== $this->incomingAssertions($assertions);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedAssertions(MonitorApis $monitorApi): array
    {
        return $monitorApi->assertions()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([
                'data_path',
                'assertion_type',
                'expected_type',
                'comparison_operator',
                'expected_value',
                'regex_pattern',
                'sort_order',
                'is_active',
            ])
            ->map(fn (MonitorApiAssertion $assertion): array => [
                'data_path' => $assertion->data_path,
                'assertion_type' => $assertion->assertion_type,
                'expected_type' => $assertion->expected_type,
                'comparison_operator' => $assertion->comparison_operator,
                'expected_value' => $assertion->expected_value === null ? null : (string) $assertion->expected_value,
                'regex_pattern' => $assertion->regex_pattern,
                'sort_order' => (int) $assertion->sort_order,
                'is_active' => (bool) $assertion->is_active,
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $assertions
     * @return array<int, array<string, mixed>>
     */
    private function incomingAssertions(array $assertions): array
    {
        return collect($assertions)
            ->values()
            ->map(fn (array $assertion, int $index): array => [
                'data_path' => $this->normalizeJsonPath($assertion['path']),
                'assertion_type' => $this->mapAssertionType($assertion['type']),
                'expected_type' => $assertion['expected_type'] ?? null,
                'comparison_operator' => $assertion['comparison_operator'] ?? (
                    $assertion['type'] === 'json_path_equals' ? '=' : null
                ),
                'expected_value' => array_key_exists('expected_value', $assertion) && $assertion['expected_value'] !== null
                    ? (string) $assertion['expected_value']
                    : null,
                'regex_pattern' => $assertion['regex_pattern'] ?? null,
                'sort_order' => $index + 1,
                'is_active' => true,
            ])
            ->all();
    }

    private function normalizeRequestBodyForComparison(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode($this->preserveEmptyJsonObjects($value));
        }

        return $value;
    }

    private function preserveEmptyJsonObjects(mixed $value, bool $isRoot = true, bool $parentIsList = false): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return $isRoot || $parentIsList ? [] : new \stdClass;
        }

        $isList = array_is_list($value);

        return array_map(
            fn (mixed $item): mixed => $this->preserveEmptyJsonObjects($item, false, $isList),
            $value,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function websiteConfigurationChanged(Website $website, array $data): bool
    {
        foreach ($data as $field => $value) {
            if ($field === 'last_synced_at') {
                continue;
            }

            if ($website->{$field} != $value) {
                return true;
            }
        }

        return false;
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

        return $query->get()
            ->each(function (MonitorApis $monitorApi) use ($syncedAt): void {
                $monitorApi->forceFill(MonitorApis::disabledHealthAttributes('Archived because it was missing from the latest package sync.') + [
                    'is_enabled' => false,
                    'last_synced_at' => $syncedAt,
                ])->save();

                $monitorApi->delete();
            })
            ->count();
    }

    /**
     * @param  array<int, string>  $activeKeys
     */
    private function disableMissingWebsiteChecks(Project $project, array $activeKeys, string $type, Carbon $syncedAt): int
    {
        $query = Website::query()
            ->where('project_id', $project->id)
            ->where('source', 'package')
            ->where($type === 'uptime' ? 'uptime_check' : 'ssl_check', true);

        if ($activeKeys !== []) {
            $query->whereNotIn('package_name', array_values(array_unique($activeKeys)));
        }

        return $query->get()
            ->each(function (Website $website) use ($type, $syncedAt): void {
                $website->forceFill([
                    $type === 'uptime' ? 'uptime_check' : 'ssl_check' => false,
                    'last_synced_at' => $syncedAt,
                ])->save();

                if (! $website->uptime_check && ! $website->ssl_check) {
                    $website->forceFill(Website::disabledLiveHealthAttributes('Archived because it was missing from the latest package sync.') + [
                        'package_interval' => null,
                    ])->save();

                    $website->delete();
                }
            })
            ->count();
    }

    private function resetStatusForFullyDisabledWebsites(Project $project): void
    {
        Website::query()
            ->where('project_id', $project->id)
            ->where('source', 'package')
            ->where('uptime_check', false)
            ->where('ssl_check', false)
            ->update(Website::disabledLiveHealthAttributes('Disabled because it was missing from the latest package sync.') + [
                'package_interval' => null,
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function websiteTargetChanged(Website $website, array $data): bool
    {
        return $website->exists
            && ! $website->trashed()
            && $website->url !== $data['url'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apiTargetChanged(MonitorApis $monitorApi, array $data, bool $assertionsChanged): bool
    {
        if (! $monitorApi->exists || $monitorApi->trashed()) {
            return false;
        }

        return $monitorApi->url !== $data['url']
            || $monitorApi->http_method !== $data['http_method']
            || (int) $monitorApi->expected_status !== (int) $data['expected_status']
            || $monitorApi->headers != $data['headers']
            || $monitorApi->request_body_type != $data['request_body_type']
            || $this->normalizeRequestBodyForComparison($monitorApi->request_body) != $this->normalizeRequestBodyForComparison($data['request_body'])
            || $monitorApi->timeout_seconds != $data['timeout_seconds']
            || $assertionsChanged;
    }

    /**
     * @return array<string, mixed>
     */
    private function pendingLiveHealthAttributes(): array
    {
        return [
            'current_status' => 'pending',
            'status_summary' => null,
            'diagnostic_queued_at' => null,
        ];
    }

    private function recalculateUptimeOnlyPackageIntervals(Project $project): void
    {
        Website::query()
            ->where('project_id', $project->id)
            ->where('source', 'package')
            ->where('uptime_check', true)
            ->where('ssl_check', false)
            ->get(['id', 'uptime_interval', 'package_interval'])
            ->each(function (Website $website): void {
                $packageInterval = IntervalParser::fromMinutes((int) $website->uptime_interval);

                if ($website->package_interval !== $packageInterval) {
                    Website::query()
                        ->where('id', $website->id)
                        ->update(['package_interval' => $packageInterval]);
                }
            });
    }

    private function resolveUrl(?string $baseUrl, string $url): string
    {
        if (preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        return rtrim((string) $baseUrl, '/').'/'.ltrim($url, '/');
    }

    private function apiSchedule(mixed $schedule): string
    {
        if ($schedule === null || (is_string($schedule) && blank($schedule))) {
            return IntervalParser::DEFAULT_API_INTERVAL;
        }

        if (! is_string($schedule)) {
            throw ValidationException::withMessages([
                'schedule' => ['The schedule format is invalid. Use format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}.'],
            ]);
        }

        return $schedule;
    }

    private function projectComponentIdFor(Project $project, mixed $componentName): ?int
    {
        if (! is_string($componentName) || blank($componentName)) {
            return null;
        }

        return ProjectComponent::query()
            ->where('project_id', $project->id)
            ->where('name', $componentName)
            ->value('id');
    }

    /**
     * @param  array{created: int, updated: int, disabled_missing: int}  $apiSummary
     * @param  array{created: int, updated: int, disabled_missing: int}  $uptimeSummary
     * @param  array{created: int, updated: int, disabled_missing: int}  $sslSummary
     * @return array<string, mixed>
     */
    private function summarize(array $apiSummary, array $uptimeSummary, array $sslSummary): array
    {
        $summaries = [$apiSummary, $uptimeSummary, $sslSummary];

        return [
            'created' => array_sum(array_column($summaries, 'created')),
            'updated' => array_sum(array_column($summaries, 'updated')),
            'disabled_missing' => array_sum(array_column($summaries, 'disabled_missing')),
            // Kept for existing package clients; unsupported check types now fail validation.
            'unsupported' => 0,
            'api_checks' => $apiSummary,
            'uptime_checks' => $uptimeSummary,
            'ssl_checks' => $sslSummary,
        ];
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
     * @param  array<string, string|null>  $defaultHeaders
     * @param  array<string, string|null>  $checkHeaders
     * @return array<string, string>
     */
    private function mergeHeaders(array $defaultHeaders, array $checkHeaders): array
    {
        $headers = $defaultHeaders;
        $headerNames = [];

        foreach (array_keys($headers) as $existingName) {
            $headerNames[strtolower((string) $existingName)] = $existingName;
        }

        foreach ($checkHeaders as $name => $value) {
            $normalizedName = strtolower((string) $name);
            $existingName = $headerNames[$normalizedName] ?? null;

            if ($existingName !== null) {
                unset($headers[$existingName]);
            }

            if ($value === null) {
                unset($headerNames[$normalizedName]);

                continue;
            }

            $headers[$name] = $value;
            $headerNames[$normalizedName] = $name;
        }

        return array_filter($headers, fn ($value): bool => $value !== null);
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
