<?php

namespace App\Services;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CheckSyncService
{
    private const MISSING_PACKAGE_SYNC_STATUS_SUMMARY = 'Disabled because it was missing from the latest package sync.';

    public function syncChecks(Project $project, array $payload): array
    {
        return DB::transaction(function () use ($project, $payload) {
            $syncedAt = now();

            $summary = [
                'uptime_checks' => $this->syncUptimeChecks($project, $payload['uptime_checks'] ?? [], $syncedAt),
                'ssl_checks' => $this->syncSslChecks($project, $payload['ssl_checks'] ?? [], $syncedAt),
                'api_checks' => $this->syncApiChecks($project, $payload['api_checks'] ?? [], $syncedAt),
            ];

            $project->fill([
                'last_synced_at' => $syncedAt,
                'latest_package_sync_summary' => $summary,
            ])->save();

            return $summary;
        });
    }

    protected function syncUptimeChecks(Project $project, array $checks, Carbon $syncedAt): array
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
            $isEnabled = $check['enabled'] ?? true;

            $data = [
                'project_id' => $project->id,
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

            if ($wasDisabledByMissingPackageSync) {
                $data += $this->awaitingLiveHealthAttributes();
            } elseif ($this->websiteTargetChanged($website, $data)) {
                $data += $this->awaitingLiveHealthAttributes();
            }

            if ($website) {
                if ($website->trashed()) {
                    $website->restore();
                }

                $website->update($data);
                $updated++;
            } else {
                Website::create($data);
                $created++;
            }
        }

        $deleted = $this->pruneOrphanedWebsites($project, $checkNames, $syncedAt, uptime: true);

        return compact('created', 'updated', 'deleted');
    }

    protected function syncSslChecks(Project $project, array $checks, Carbon $syncedAt): array
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
            $isEnabled = $check['enabled'] ?? true;

            $data = [
                'project_id' => $project->id,
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

            if ($wasDisabledByMissingPackageSync) {
                $data += $this->awaitingLiveHealthAttributes();
            } elseif ($this->websiteTargetChanged($website, $data)) {
                $data += $this->awaitingLiveHealthAttributes();
            }

            if ($website) {
                if ($website->trashed()) {
                    $website->restore();
                }

                $website->update($data);
                $updated++;
            } else {
                Website::create($data);
                $created++;
            }
        }

        $deleted = $this->pruneOrphanedWebsites($project, $checkNames, $syncedAt, ssl: true);

        return compact('created', 'updated', 'deleted');
    }

    protected function syncApiChecks(Project $project, array $checks, Carbon $syncedAt): array
    {
        $created = 0;
        $updated = 0;
        $checkNames = array_values(array_unique(array_column($checks, 'name')));
        $existingApis = MonitorApis::withTrashed()
            ->where('project_id', $project->id)
            ->where('source', 'package')
            ->whereIn('package_name', $checkNames)
            ->get()
            ->keyBy('package_name');

        foreach ($checks as $check) {
            $monitorApi = $existingApis->get($check['name']);
            $wasDisabledByMissingPackageSync = $this->wasApiDisabledByMissingPackageSync($monitorApi);
            $isEnabled = array_key_exists('enabled', $check)
                ? ($check['enabled'] ?? true)
                : ($wasDisabledByMissingPackageSync ? true : ($monitorApi?->is_enabled ?? true));

            $data = [
                'project_id' => $project->id,
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

            if ($wasDisabledByMissingPackageSync) {
                $data += $this->awaitingLiveHealthAttributes();
            } elseif ($this->apiTargetChanged($monitorApi, $data, $check['assertions'] ?? [])) {
                $data += $this->awaitingLiveHealthAttributes();
            }

            if ($monitorApi) {
                if ($monitorApi->trashed()) {
                    $monitorApi->restore();
                }

                $monitorApi->update($data);
                $updated++;
            } else {
                $monitorApi = MonitorApis::create($data);
                $created++;
                $existingApis->put($check['name'], $monitorApi);
            }

            $this->syncAssertions($monitorApi, $check['assertions'] ?? []);
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

        return $query->update(MonitorApis::disabledHealthAttributes(self::MISSING_PACKAGE_SYNC_STATUS_SUMMARY) + [
            'is_enabled' => false,
            'last_synced_at' => $syncedAt,
        ]);
    }

    protected function disableFullyOrphanedWebsites(Builder $query, Carbon $syncedAt): int
    {
        return $query->update(Website::disabledLiveHealthAttributes(self::MISSING_PACKAGE_SYNC_STATUS_SUMMARY) + [
            'uptime_check' => false,
            'ssl_check' => false,
            'package_interval' => null,
            'last_heartbeat_at' => null,
            'last_synced_at' => $syncedAt,
        ]);
    }

    protected function wasApiDisabledByMissingPackageSync(?MonitorApis $monitorApi): bool
    {
        return $monitorApi instanceof MonitorApis
            && ! $monitorApi->trashed()
            && ! $monitorApi->is_enabled
            && $monitorApi->status_summary === self::MISSING_PACKAGE_SYNC_STATUS_SUMMARY;
    }

    protected function wasWebsiteDisabledByMissingPackageSync(?Website $website): bool
    {
        return $website instanceof Website
            && ! $website->trashed()
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
     * @param  array<int, array<string, mixed>>  $assertions
     */
    protected function apiTargetChanged(?MonitorApis $monitorApi, array $data, array $assertions): bool
    {
        if (! $monitorApi instanceof MonitorApis || $monitorApi->trashed()) {
            return false;
        }

        return $monitorApi->url !== $data['url']
            || $monitorApi->http_method !== $data['http_method']
            || (int) $monitorApi->expected_status !== (int) $data['expected_status']
            || $this->canonicalExistingAssertions($monitorApi) !== $this->canonicalIncomingLegacyAssertions($assertions);
    }

    /**
     * @return array<string, mixed>
     */
    protected function awaitingLiveHealthAttributes(): array
    {
        return [
            'current_status' => 'unknown',
            'status_summary' => null,
            'last_heartbeat_at' => null,
            'awaiting_heartbeat_since' => now(),
            'stale_at' => null,
            'diagnostic_queued_at' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function canonicalExistingAssertions(MonitorApis $monitorApi): array
    {
        return MonitorApiAssertion::query()
            ->where('monitor_api_id', $monitorApi->id)
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
