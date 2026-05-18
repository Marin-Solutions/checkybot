<?php

namespace App\Services;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CheckSyncService
{
    private const MISSING_PACKAGE_SYNC_STATUS_SUMMARY = 'Disabled because it was missing from the latest package sync.';

    public function syncChecks(Project $project, array $payload): array
    {
        $this->validateComponentReferences($project, $payload);

        return DB::transaction(function () use ($project, $payload) {
            $syncedAt = now();
            $componentIdsByName = $this->projectComponentIdsByName($project);

            $summary = [
                'uptime_checks' => $this->syncUptimeChecks($project, $payload['uptime_checks'] ?? [], $syncedAt, $componentIdsByName),
                'ssl_checks' => $this->syncSslChecks($project, $payload['ssl_checks'] ?? [], $syncedAt, $componentIdsByName),
                'api_checks' => $this->syncApiChecks($project, $payload['api_checks'] ?? [], $syncedAt, $componentIdsByName),
            ];

            $project->fill([
                'last_synced_at' => $syncedAt,
                'latest_package_sync_summary' => $summary,
            ])->save();

            return $summary;
        });
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $payload
     */
    private function validateComponentReferences(Project $project, array $payload): void
    {
        $componentIdsByName = $this->projectComponentIdsByName($project);
        $errors = [];

        foreach (['uptime_checks', 'ssl_checks', 'api_checks'] as $checkGroup) {
            foreach ($payload[$checkGroup] ?? [] as $index => $check) {
                $component = $check['component'] ?? null;

                if (! is_string($component) || blank($component) || isset($componentIdsByName[$component])) {
                    continue;
                }

                $errors["{$checkGroup}.{$index}.component"][] = "The component \"{$component}\" has not been declared for this project. Sync it through declared_components or fix the component name.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, int>  $componentIdsByName
     */
    protected function syncUptimeChecks(Project $project, array $checks, Carbon $syncedAt, array $componentIdsByName): array
    {
        $created = 0;
        $updated = 0;
        $checkNames = array_values(array_unique(array_column($checks, 'name')));
        $existingWebsites = Website::withTrashed()
            ->where('project_id', $project->id)
            ->where('source', 'package')
            ->whereIn('package_name', $checkNames)
            ->get()
            ->keyBy('package_name');

        foreach ($checks as $check) {
            $website = $existingWebsites->get($check['name']);
            $wasDisabledByMissingPackageSync = $this->wasWebsiteDisabledByMissingPackageSync($website);
            $wasRestored = false;
            $isEnabled = $check['enabled'] ?? true;

            $data = [
                'project_id' => $project->id,
                'project_component_id' => $this->projectComponentIdFor($componentIdsByName, $check['component'] ?? null),
                'name' => $check['name'],
                'url' => $this->resolveUrl($project->base_url, $check['url']),
                'description' => '',
                'uptime_check' => $isEnabled,
                'ssl_check' => ($website && ! $website->trashed()) ? $website->ssl_check : false,
                'uptime_interval' => IntervalParser::toMinutes($check['interval']),
                'source' => 'package',
                'package_name' => $check['name'],
                'package_interval' => $check['interval'],
                'created_by' => $project->created_by,
                'last_synced_at' => $syncedAt,
            ];

            if ($website) {
                if ($website->trashed()) {
                    $website->restore();
                    $wasRestored = true;
                }

                $configurationChanged = $wasRestored || $this->websiteConfigurationChanged($website, $data);

                if ($wasDisabledByMissingPackageSync || $wasRestored || $this->websiteTargetChanged($website, $data)) {
                    $data += $this->pendingLiveHealthAttributes();
                }

                $website->update($data);

                if ($configurationChanged) {
                    $updated++;
                }
            } else {
                Website::create($data + $this->pendingLiveHealthAttributes());
                $created++;
            }
        }

        $deleted = $this->pruneOrphanedWebsites($project, $checkNames, $syncedAt, uptime: true);

        return compact('created', 'updated', 'deleted');
    }

    /**
     * @param  array<string, int>  $componentIdsByName
     */
    protected function syncSslChecks(Project $project, array $checks, Carbon $syncedAt, array $componentIdsByName): array
    {
        $created = 0;
        $updated = 0;
        $checkNames = array_values(array_unique(array_column($checks, 'name')));
        $existingWebsites = Website::withTrashed()
            ->where('project_id', $project->id)
            ->where('source', 'package')
            ->whereIn('package_name', $checkNames)
            ->get()
            ->keyBy('package_name');

        foreach ($checks as $check) {
            $website = $existingWebsites->get($check['name']);
            $wasDisabledByMissingPackageSync = $this->wasWebsiteDisabledByMissingPackageSync($website);
            $wasRestored = false;
            $isEnabled = $check['enabled'] ?? true;

            $data = [
                'project_id' => $project->id,
                'project_component_id' => $this->projectComponentIdFor($componentIdsByName, $check['component'] ?? null),
                'name' => $check['name'],
                'url' => $this->resolveUrl($project->base_url, $check['url']),
                'description' => '',
                'uptime_check' => ($website && ! $website->trashed()) ? $website->uptime_check : false,
                'ssl_check' => $isEnabled,
                'uptime_interval' => ($website && ! $website->trashed() && $website->uptime_check)
                    ? $website->uptime_interval
                    : IntervalParser::toMinutes($check['interval']),
                'source' => 'package',
                'package_name' => $check['name'],
                'package_interval' => $check['interval'],
                'created_by' => $project->created_by,
                'last_synced_at' => $syncedAt,
            ];

            if ($website) {
                if ($website->trashed()) {
                    $website->restore();
                    $wasRestored = true;
                }

                $configurationChanged = $wasRestored || $this->websiteConfigurationChanged($website, $data);

                if ($wasDisabledByMissingPackageSync || $wasRestored || $this->websiteTargetChanged($website, $data)) {
                    $data += $this->pendingLiveHealthAttributes();
                }

                $website->update($data);

                if ($configurationChanged) {
                    $updated++;
                }
            } else {
                Website::create($data + $this->pendingLiveHealthAttributes());
                $created++;
            }
        }

        $deleted = $this->pruneOrphanedWebsites($project, $checkNames, $syncedAt, ssl: true);

        return compact('created', 'updated', 'deleted');
    }

    /**
     * @param  array<string, int>  $componentIdsByName
     */
    protected function syncApiChecks(Project $project, array $checks, Carbon $syncedAt, array $componentIdsByName): array
    {
        $created = 0;
        $updated = 0;
        $checkNames = array_values(array_unique(array_column($checks, 'name')));
        $existingApis = MonitorApis::withTrashed()
            ->with('assertions')
            ->where('project_id', $project->id)
            ->where('source', 'package')
            ->whereIn('package_name', $checkNames)
            ->get()
            ->keyBy('package_name');

        foreach ($checks as $check) {
            $monitorApi = $existingApis->get($check['name']);
            $wasDisabledByMissingPackageSync = $this->wasApiDisabledByMissingPackageSync($monitorApi);
            $wasRestored = false;
            $isEnabled = array_key_exists('enabled', $check)
                ? ($check['enabled'] ?? true)
                : ($wasDisabledByMissingPackageSync ? true : ($monitorApi?->is_enabled ?? true));

            $data = [
                'project_id' => $project->id,
                'project_component_id' => $this->projectComponentIdFor($componentIdsByName, $check['component'] ?? null),
                'title' => $check['name'],
                'url' => $this->resolveUrl($project->base_url, $check['url']),
                'http_method' => array_key_exists('method', $check)
                    ? strtoupper($check['method'] ?? 'GET')
                    : ($monitorApi?->http_method ?? 'GET'),
                'request_path' => $check['url'],
                'data_path' => '',
                'headers' => $check['headers'] ?? [],
                'request_body_type' => $check['request_body_type'] ?? null,
                'request_body' => $check['request_body'] ?? null,
                'expected_status' => array_key_exists('expected_status', $check)
                    ? ($check['expected_status'] ?? 200)
                    : ($monitorApi?->expected_status ?? 200),
                'timeout_seconds' => array_key_exists('timeout_seconds', $check)
                    ? $check['timeout_seconds']
                    : $monitorApi?->timeout_seconds,
                'save_failed_response' => array_key_exists('save_failed_response', $check)
                    ? ($check['save_failed_response'] ?? true)
                    : ($monitorApi?->save_failed_response ?? true),
                'package_schedule' => $check['interval'],
                'is_enabled' => $isEnabled,
                'source' => 'package',
                'package_name' => $check['name'],
                'package_interval' => $check['interval'],
                'created_by' => $project->created_by,
                'last_synced_at' => $syncedAt,
            ];

            $incomingAssertions = $this->canonicalIncomingLegacyAssertions($check['assertions'] ?? []);
            $existingAssertions = $monitorApi instanceof MonitorApis
                ? $this->canonicalExistingAssertions($monitorApi)
                : [];
            $assertionsChanged = $existingAssertions !== $incomingAssertions;

            if ($monitorApi) {
                if ($monitorApi->trashed()) {
                    $monitorApi->restore();
                    $wasRestored = true;
                }

                $configurationChanged = $wasRestored || $this->apiConfigurationChanged($monitorApi, $data);

                if ($wasDisabledByMissingPackageSync || $wasRestored || $this->apiTargetChanged($monitorApi, $data, $assertionsChanged)) {
                    $data += $this->pendingLiveHealthAttributes();
                }

                $monitorApi->update($data);

                if ($assertionsChanged) {
                    $this->syncAssertions($monitorApi, $check['assertions'] ?? []);
                }

                if ($configurationChanged || $assertionsChanged) {
                    $updated++;
                }
            } else {
                $monitorApi = MonitorApis::create($data + $this->pendingLiveHealthAttributes());
                $created++;
                $existingApis->put($check['name'], $monitorApi);
                $this->syncAssertions($monitorApi, $check['assertions'] ?? []);
            }
        }

        $deleted = $this->pruneOrphanedApis($project, $checkNames, $syncedAt);

        return compact('created', 'updated', 'deleted');
    }

    protected function syncAssertions(MonitorApis $monitorApi, array $assertions): void
    {
        MonitorApiAssertion::where('monitor_api_id', $monitorApi->id)->delete();

        foreach ($assertions as $assertion) {
            MonitorApiAssertion::create([
                'monitor_api_id' => $monitorApi->id,
                'data_path' => $assertion['data_path'],
                'assertion_type' => $assertion['assertion_type'],
                'expected_type' => $assertion['expected_type'] ?? null,
                'comparison_operator' => $assertion['comparison_operator'] ?? null,
                'expected_value' => array_key_exists('expected_value', $assertion) && $assertion['expected_value'] !== null
                    ? (string) $assertion['expected_value']
                    : null,
                'regex_pattern' => $assertion['regex_pattern'] ?? null,
                'sort_order' => $assertion['sort_order'] ?? 1,
                'is_active' => $assertion['is_active'] ?? true,
            ]);
        }
    }

    private function resolveUrl(?string $baseUrl, string $url): string
    {
        if (preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        return rtrim((string) $baseUrl, '/').'/'.ltrim($url, '/');
    }

    /**
     * @return array<string, int>
     */
    private function projectComponentIdsByName(Project $project): array
    {
        return ProjectComponent::query()
            ->where('project_id', $project->id)
            ->pluck('id', 'name')
            ->all();
    }

    /**
     * @param  array<string, int>  $componentIdsByName
     */
    private function projectComponentIdFor(array $componentIdsByName, mixed $componentName): ?int
    {
        if (! is_string($componentName) || blank($componentName)) {
            return null;
        }

        return $componentIdsByName[$componentName] ?? null;
    }

    protected function pruneOrphanedWebsites(Project $project, array $keepNames, Carbon $syncedAt, bool $uptime = false, bool $ssl = false): int
    {
        if ($uptime && $ssl) {
            throw new InvalidArgumentException('Package website pruning must target one check type at a time.');
        }

        $query = Website::where('project_id', $project->id)
            ->where('source', 'package');

        if ($uptime) {
            $query->where('uptime_check', true);
        }

        if ($ssl) {
            $query->where('ssl_check', true);
        }

        if (! empty($keepNames)) {
            $query->whereNotIn('package_name', $keepNames);
        }

        if ($uptime) {
            (clone $query)
                ->where('ssl_check', true)
                ->update([
                    'uptime_check' => false,
                    'last_synced_at' => $syncedAt,
                ]);

            return $this->disableFullyOrphanedWebsites(
                (clone $query)->where('ssl_check', false),
                $syncedAt
            );
        }

        if ($ssl) {
            (clone $query)
                ->where('uptime_check', true)
                ->update([
                    'ssl_check' => false,
                    'last_synced_at' => $syncedAt,
                ]);

            return $this->disableFullyOrphanedWebsites(
                (clone $query)->where('uptime_check', false),
                $syncedAt
            );
        }

        return $this->disableFullyOrphanedWebsites($query, $syncedAt);
    }

    protected function pruneOrphanedApis(Project $project, array $keepNames, Carbon $syncedAt): int
    {
        $query = MonitorApis::where('project_id', $project->id)
            ->where('source', 'package')
            ->where('is_enabled', true);

        if (! empty($keepNames)) {
            $query->whereNotIn('package_name', $keepNames);
        }

        return $query->get()
            ->each(function (MonitorApis $monitorApi) use ($syncedAt): void {
                $monitorApi->forceFill(MonitorApis::disabledHealthAttributes(self::MISSING_PACKAGE_SYNC_STATUS_SUMMARY) + [
                    'is_enabled' => false,
                    'last_synced_at' => $syncedAt,
                ])->save();

                $monitorApi->delete();
            })
            ->count();
    }

    protected function disableFullyOrphanedWebsites(Builder $query, Carbon $syncedAt): int
    {
        return $query->get()
            ->each(function (Website $website) use ($syncedAt): void {
                $website->forceFill(Website::disabledLiveHealthAttributes(self::MISSING_PACKAGE_SYNC_STATUS_SUMMARY) + [
                    'uptime_check' => false,
                    'ssl_check' => false,
                    'package_interval' => null,
                    'last_synced_at' => $syncedAt,
                ])->save();

                $website->delete();
            })
            ->count();
    }

    protected function wasApiDisabledByMissingPackageSync(?MonitorApis $monitorApi): bool
    {
        return $monitorApi instanceof MonitorApis
            && ! $monitorApi->is_enabled
            && $monitorApi->status_summary === self::MISSING_PACKAGE_SYNC_STATUS_SUMMARY;
    }

    protected function wasWebsiteDisabledByMissingPackageSync(?Website $website): bool
    {
        return $website instanceof Website
            && ! $website->uptime_check
            && ! $website->ssl_check
            && $website->status_summary === self::MISSING_PACKAGE_SYNC_STATUS_SUMMARY;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function websiteTargetChanged(?Website $website, array $data): bool
    {
        return $website instanceof Website
            && ! $website->trashed()
            && $website->url !== $data['url'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function websiteConfigurationChanged(Website $website, array $data): bool
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
     * @param  array<string, mixed>  $data
     */
    protected function apiTargetChanged(?MonitorApis $monitorApi, array $data, bool $assertionsChanged): bool
    {
        if (! $monitorApi instanceof MonitorApis || $monitorApi->trashed()) {
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
     * @param  array<string, mixed>  $data
     */
    protected function apiConfigurationChanged(MonitorApis $monitorApi, array $data): bool
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

    protected function normalizeRequestBodyForComparison(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode($this->preserveEmptyJsonObjects($value));
        }

        return $value;
    }

    protected function preserveEmptyJsonObjects(mixed $value, bool $isRoot = true, bool $parentIsList = false): mixed
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
     * @return array<string, mixed>
     */
    protected function pendingLiveHealthAttributes(): array
    {
        return [
            'current_status' => 'pending',
            'status_summary' => null,
            'diagnostic_queued_at' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function canonicalExistingAssertions(MonitorApis $monitorApi): array
    {
        $assertions = $monitorApi->relationLoaded('assertions')
            ? $monitorApi->assertions
            : MonitorApiAssertion::query()
                ->where('monitor_api_id', $monitorApi->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get([
                    'id',
                    'data_path',
                    'assertion_type',
                    'expected_type',
                    'comparison_operator',
                    'expected_value',
                    'regex_pattern',
                    'sort_order',
                    'is_active',
                ]);

        return $assertions
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
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
            ->sortBy($this->assertionSortFields())
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $assertions
     * @return array<int, array<string, mixed>>
     */
    protected function canonicalIncomingLegacyAssertions(array $assertions): array
    {
        return collect($assertions)
            ->map(fn (array $assertion): array => [
                'data_path' => $assertion['data_path'],
                'assertion_type' => $assertion['assertion_type'],
                'expected_type' => $assertion['expected_type'] ?? null,
                'comparison_operator' => $assertion['comparison_operator'] ?? null,
                'expected_value' => array_key_exists('expected_value', $assertion) && $assertion['expected_value'] !== null
                    ? (string) $assertion['expected_value']
                    : null,
                'regex_pattern' => $assertion['regex_pattern'] ?? null,
                'sort_order' => (int) ($assertion['sort_order'] ?? 1),
                'is_active' => (bool) ($assertion['is_active'] ?? true),
            ])
            ->sortBy($this->assertionSortFields())
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    protected function assertionSortFields(): array
    {
        return [
            ['sort_order', 'asc'],
            ['data_path', 'asc'],
            ['assertion_type', 'asc'],
            ['expected_type', 'asc'],
            ['comparison_operator', 'asc'],
            ['expected_value', 'asc'],
            ['regex_pattern', 'asc'],
            ['is_active', 'asc'],
        ];
    }
}
