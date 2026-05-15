<?php

namespace App\Services;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\ProjectComponentHeartbeat;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Support\ApiMonitorEvidenceRedactor;
use App\Support\ProjectComponentDeliveryState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class CheckybotImportService
{
    /**
     * @return array<string, mixed>
     */
    public function getProject(User $user, string|int $projectKey): array
    {
        $project = $this->findProject($user, $projectKey)
            ->loadCount([
                'packageManagedApis as api_checks_count',
                'components as component_checks_count' => fn (Builder $query) => $query->where('source', 'package'),
            ]);
        $websiteChecksCount = $this->countWebsiteChecks($project);
        $componentChecksCount = (int) $project->component_checks_count;

        return [
            'id' => $project->id,
            'key' => $project->package_key,
            'name' => $project->name,
            'environment' => $project->environment,
            'group' => $project->group,
            'technology' => $project->technology,
            'identity_endpoint' => $project->identity_endpoint,
            'base_url' => $project->base_url,
            'repository' => $project->repository,
            'checks_count' => (int) $project->api_checks_count + $websiteChecksCount + $componentChecksCount,
            'api_checks_count' => (int) $project->api_checks_count,
            'website_checks_count' => $websiteChecksCount,
            'component_checks_count' => $componentChecksCount,
            'last_synced_at' => $project->last_synced_at?->toISOString(),
            'created_at' => $project->created_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listChecks(User $user, string|int $projectKey): array
    {
        $project = $this->findProject($user, $projectKey);

        return $this->checksForProject($project)
            ->sortBy([
                ['type', 'asc'],
                ['key', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getCheck(User $user, string|int $projectKey, string $checkKey, ?string $type = null): array
    {
        $project = $this->findProject($user, $projectKey);

        return $this->findCheck($project, $checkKey, $type);
    }

    /**
     * @return array<string, mixed>
     */
    private function findCheck(Project $project, string $checkKey, ?string $type = null): array
    {
        $checks = $this->checksForProject($project);

        if ($type !== null) {
            $checks = $checks
                ->filter(fn (array $check): bool => $check['type'] === $type)
                ->values();
        }

        $idMatches = $checks
            ->filter(fn (array $check): bool => $check['id'] === $checkKey)
            ->values();

        if ($idMatches->count() === 1) {
            return $idMatches->first();
        }

        if ($idMatches->count() > 1) {
            throw (new ModelNotFoundException)->setModel(MonitorApis::class, [$checkKey]);
        }

        $keyMatches = $checks
            ->filter(fn (array $check): bool => $check['key'] === $checkKey)
            ->values();

        if ($keyMatches->count() === 2) {
            $databaseIds = $keyMatches
                ->pluck('database_id')
                ->unique()
                ->values();
            $types = $keyMatches
                ->pluck('type')
                ->sort()
                ->values()
                ->all();

            if ($databaseIds->count() === 1 && $types === ['ssl', 'uptime']) {
                return $keyMatches->firstWhere('type', 'uptime') ?? $keyMatches->first();
            }
        }

        if ($keyMatches->count() !== 1) {
            throw (new ModelNotFoundException)->setModel(MonitorApis::class, [$checkKey]);
        }

        return $keyMatches->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentResults(
        User $user,
        string|int $projectKey,
        string $checkKey,
        int $limit = 25,
        string $runSource = 'all',
        ?string $type = null,
    ): array {
        $project = $this->findProject($user, $projectKey);
        $check = $this->findCheck($project, $checkKey, $type);
        $limit = min(max($limit, 1), 100);

        if ($check['storage'] === 'monitor_api') {
            $query = MonitorApiResult::query()
                ->with('monitorApi.project')
                ->where('monitor_api_id', $check['database_id']);

            $this->applyRunSourceFilter($query, $runSource);

            return $query
                ->latest()
                ->limit($limit)
                ->get()
                ->map(fn (MonitorApiResult $result): array => $this->apiResultPayload($result))
                ->all();
        }

        if ($check['storage'] === 'project_component') {
            if (! in_array($runSource, ['all', 'heartbeat'], true)) {
                return [];
            }

            return ProjectComponentHeartbeat::query()
                ->where('project_component_id', $check['database_id'])
                ->orderByDesc('observed_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
                ->map(fn (ProjectComponentHeartbeat $heartbeat): array => $this->componentHeartbeatPayload($heartbeat))
                ->all();
        }

        $query = WebsiteLogHistory::query()
            ->where('website_id', $check['database_id']);

        $this->applyRunSourceFilter($query, $runSource);

        return $query
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (WebsiteLogHistory $result): array => $this->websiteResultPayload($result, $check))
            ->all();
    }

    private function applyRunSourceFilter(Builder $query, string $runSource): void
    {
        if ($runSource === 'scheduled') {
            $query->where('is_on_demand', false);

            return;
        }

        if ($runSource === 'on_demand') {
            $query->where('is_on_demand', true);

            return;
        }

        if ($runSource === 'heartbeat') {
            $query->whereRaw('1 = 0');
        }
    }

    public function findProject(User $user, string|int $projectKey): Project
    {
        $matches = Project::query()
            ->where('created_by', $user->id)
            ->where(function (Builder $query) use ($projectKey): void {
                $query->where('package_key', (string) $projectKey);

                if ($this->isCanonicalNumericId($projectKey)) {
                    $query->orWhere('id', (int) $projectKey);
                }
            })
            ->limit(2)
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        throw (new ModelNotFoundException)->setModel(Project::class, [$projectKey]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function checksForProject(Project $project): Collection
    {
        $websiteChecks = $project->packageManagedWebsites()
            ->with(['latestLogHistory', 'latestDiagnosticLogHistory'])
            ->orderBy('package_name')
            ->get()
            ->flatMap(fn (Website $website): array => $this->websiteCheckPayloads($website));

        $apiChecks = $project->packageManagedApis()
            ->with(['assertions', 'latestResult', 'latestDiagnosticResult'])
            ->orderBy('package_name')
            ->get()
            ->map(fn (MonitorApis $check): array => $this->apiCheckPayload($check));

        $componentChecks = $project->components()
            ->where('source', 'package')
            ->with('latestHeartbeat')
            ->orderBy('name')
            ->get()
            ->map(fn (ProjectComponent $component): array => $this->componentCheckPayload($component));

        return $websiteChecks
            ->concat($apiChecks)
            ->concat($componentChecks)
            ->values();
    }

    private function countWebsiteChecks(Project $project): int
    {
        $uptimeChecksCount = (int) $project->packageManagedWebsites()
            ->where('uptime_check', true)
            ->count();
        $sslChecksCount = (int) $project->packageManagedWebsites()
            ->where('ssl_check', true)
            ->count();

        return $uptimeChecksCount + $sslChecksCount;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function websiteCheckPayloads(Website $website): array
    {
        $checks = [];

        if ($website->uptime_check) {
            $checks[] = $this->websiteCheckPayload($website, 'uptime');
        }

        if ($website->ssl_check) {
            $checks[] = $this->websiteCheckPayload($website, 'ssl');
        }

        return $checks;
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteCheckPayload(Website $website, string $type): array
    {
        $latestResult = $website->relationLoaded('latestLogHistory')
            ? $website->latestLogHistory
            : $website->logHistory()->latest()->first();
        $latestDiagnosticResult = $website->relationLoaded('latestDiagnosticLogHistory')
            ? $website->latestDiagnosticLogHistory
            : $website->latestDiagnosticLogHistory()->first();

        $key = $website->package_name ?: (string) $website->id;
        $identity = [
            'id' => "{$type}:{$website->id}",
            'database_id' => $website->id,
            'key' => $key,
            'type' => $type,
            'name' => $website->name,
        ];

        return [
            'id' => $identity['id'],
            'database_id' => $website->id,
            'key' => $key,
            'type' => $type,
            'storage' => 'website',
            'name' => $website->name,
            'target' => $this->sanitizeUrl($website->url),
            'url' => $this->sanitizeUrl($website->url),
            'interval' => $website->package_interval,
            'interval_minutes' => $type === 'uptime'
                ? $website->uptime_interval
                : ($website->package_interval !== null ? IntervalParser::toMinutes($website->package_interval) : null),
            'enabled' => $type === 'uptime' ? $website->uptime_check : $website->ssl_check,
            'status' => $website->current_status ?? 'unknown',
            'status_summary' => $website->status_summary,
            'last_checked_at' => $website->last_heartbeat_at?->toISOString(),
            'last_heartbeat_at' => $website->last_heartbeat_at?->toISOString(),
            'stale_at' => $website->stale_at?->toISOString(),
            'diagnostic_queued' => $website->hasQueuedDiagnostic(),
            'diagnostic_queued_at' => $website->diagnostic_queued_at?->toISOString(),
            'silenced_until' => $website->silenced_until?->toISOString(),
            'latest_result' => $latestResult instanceof WebsiteLogHistory
                ? $this->websiteResultPayload($latestResult, $identity)
                : null,
            'latest_diagnostic_result' => $latestDiagnosticResult instanceof WebsiteLogHistory
                ? $this->websiteResultPayload($latestDiagnosticResult, $identity)
                : null,
            'raw' => [
                'website_id' => $website->id,
                'package_name' => $website->package_name,
                'source' => $website->source,
                'ssl_expiry_date' => $website->ssl_expiry_date,
                'uptime_check' => $website->uptime_check,
                'ssl_check' => $website->ssl_check,
            ],
            'last_synced_at' => $website->last_synced_at?->toISOString(),
            'created_at' => $website->created_at?->toISOString(),
            'updated_at' => $website->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function apiCheckPayload(MonitorApis $check): array
    {
        $latestResult = $check->relationLoaded('latestResult')
            ? $check->latestResult
            : $check->results()->latest()->first();
        $latestDiagnosticResult = $check->relationLoaded('latestDiagnosticResult')
            ? $check->latestDiagnosticResult
            : $check->latestDiagnosticResult()->first();

        return [
            'id' => "api:{$check->id}",
            'database_id' => $check->id,
            'key' => $check->package_name,
            'type' => 'api',
            'storage' => 'monitor_api',
            'name' => $check->title,
            'target' => $this->sanitizeUrl($check->url),
            'url' => $this->sanitizeUrl($check->url),
            'method' => $check->http_method,
            'request_path' => $this->sanitizeUrl($check->request_path),
            'expected_status' => $check->expected_status,
            'timeout_seconds' => $check->timeout_seconds,
            'interval' => $check->package_interval,
            'schedule' => $check->package_schedule,
            'enabled' => $check->is_enabled,
            'status' => $check->current_status ?? 'unknown',
            'status_summary' => $check->status_summary,
            'last_checked_at' => $check->last_heartbeat_at?->toISOString(),
            'last_heartbeat_at' => $check->last_heartbeat_at?->toISOString(),
            'stale_at' => $check->stale_at?->toISOString(),
            'diagnostic_queued' => $check->hasQueuedDiagnostic(),
            'diagnostic_queued_at' => $check->diagnostic_queued_at?->toISOString(),
            'silenced_until' => $check->silenced_until?->toISOString(),
            'headers' => $this->redactHeaders($check->headers),
            'request_body_type' => $check->request_body_type,
            'has_request_body' => $check->hasRequestBody(),
            'assertions' => $this->assertionsPayload($check->assertions),
            'latest_result' => $latestResult instanceof MonitorApiResult ? $this->apiResultPayload($latestResult) : null,
            'latest_diagnostic_result' => $latestDiagnosticResult instanceof MonitorApiResult ? $this->apiResultPayload($latestDiagnosticResult) : null,
            'raw' => [
                'monitor_api_id' => $check->id,
                'package_name' => $check->package_name,
                'source' => $check->source,
                'data_path' => $check->data_path,
                'save_failed_response' => $check->save_failed_response,
            ],
            'last_synced_at' => $check->last_synced_at?->toISOString(),
            'created_at' => $check->created_at?->toISOString(),
            'updated_at' => $check->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentCheckPayload(ProjectComponent $component): array
    {
        $latestHeartbeat = $component->relationLoaded('latestHeartbeat')
            ? $component->latestHeartbeat
            : $component->latestHeartbeat()->first();

        $deliveryState = ProjectComponentDeliveryState::value($component);

        return [
            'id' => "component:{$component->id}",
            'database_id' => $component->id,
            'key' => $component->name,
            'type' => 'component',
            'storage' => 'project_component',
            'name' => $component->name,
            'target' => null,
            'url' => null,
            'interval' => $component->declared_interval,
            'declared_interval' => $component->declared_interval,
            'interval_minutes' => $component->interval_minutes,
            'enabled' => ! (bool) $component->is_archived,
            'status' => $this->componentStatus($component),
            'reported_status' => $component->last_reported_status,
            'status_summary' => $component->summary,
            'delivery_state' => $deliveryState,
            'delivery_state_label' => ProjectComponentDeliveryState::label($component),
            'is_stale' => (bool) $component->is_stale,
            'is_archived' => (bool) $component->is_archived,
            'last_checked_at' => $component->last_heartbeat_at?->toISOString(),
            'last_heartbeat_at' => $component->last_heartbeat_at?->toISOString(),
            'stale_at' => $component->stale_detected_at?->toISOString(),
            'stale_detected_at' => $component->stale_detected_at?->toISOString(),
            'stale_threshold_at' => $this->componentStaleThresholdAt($component),
            'silenced_until' => $component->silenced_until?->toISOString(),
            'metrics' => $component->metrics,
            'latest_result' => $latestHeartbeat instanceof ProjectComponentHeartbeat
                ? $this->componentHeartbeatPayload($latestHeartbeat)
                : null,
            'raw' => [
                'project_component_id' => $component->id,
                'source' => $component->source,
                'archive_reason' => $component->archive_reason,
            ],
            'last_synced_at' => null,
            'created_at' => $component->created_at?->toISOString(),
            'updated_at' => $component->updated_at?->toISOString(),
        ];
    }

    private function componentStatus(ProjectComponent $component): string
    {
        if ((bool) $component->is_stale) {
            return 'danger';
        }

        if ($component->last_heartbeat_at === null && $component->current_status === 'healthy') {
            return 'unknown';
        }

        return $component->current_status ?? 'unknown';
    }

    private function componentStaleThresholdAt(ProjectComponent $component): ?string
    {
        if ($component->interval_minutes === null) {
            return null;
        }

        $anchorAt = $component->last_heartbeat_at ?? $component->created_at;

        if ($anchorAt === null) {
            return null;
        }

        $graceMinutes = max(0, (int) config('monitor.project_component_stale_grace_minutes'));

        return $anchorAt
            ->copy()
            ->addMinutes($component->interval_minutes + $graceMinutes)
            ->toISOString();
    }

    /**
     * @param  iterable<MonitorApiAssertion>  $assertions
     * @return array<int, array<string, mixed>>
     */
    private function assertionsPayload(iterable $assertions): array
    {
        return collect($assertions)
            ->values()
            ->map(fn (MonitorApiAssertion $assertion): array => [
                'data_path' => $assertion->data_path,
                'assertion_type' => $assertion->assertion_type,
                'expected_type' => $assertion->expected_type,
                'comparison_operator' => $assertion->comparison_operator,
                'expected_value' => $assertion->expected_value,
                'regex_pattern' => $assertion->regex_pattern,
                'sort_order' => $assertion->sort_order,
                'is_active' => $assertion->is_active,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function apiResultPayload(MonitorApiResult $result): array
    {
        return [
            'id' => $result->id,
            'check_id' => "api:{$result->monitor_api_id}",
            'success' => $result->is_success,
            'status' => $result->status ?? ($result->is_success ? 'healthy' : 'danger'),
            'summary' => $result->summary,
            'run_source' => $result->run_source->value,
            'is_on_demand' => (bool) $result->is_on_demand,
            'http_code' => $result->http_code,
            'response_time_ms' => $result->response_time_ms,
            'transport_error_type' => $result->transport_error_type,
            'transport_error_message' => ApiMonitorEvidenceRedactor::redactTransportErrorMessage($result->transport_error_message),
            'transport_error_code' => $result->transport_error_code,
            'failed_assertions' => $result->failed_assertions,
            'request_headers' => ApiMonitorEvidenceRedactor::redactHeaders($result->request_headers ?? []),
            'response_headers' => ApiMonitorEvidenceRedactor::redactHeaders($result->response_headers ?? []),
            'response_body' => ApiMonitorEvidenceRedactor::redactResponseBody($result->response_body),
            'checked_at' => $result->created_at?->toISOString(),
            'created_at' => $result->created_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function websiteResultPayload(WebsiteLogHistory $result, array $check): array
    {
        return [
            'id' => $result->id,
            'check_id' => $check['id'],
            'success' => in_array($result->status, ['healthy', null], true)
                && ((int) ($result->http_status_code ?? 200)) < 400,
            'status' => $result->status ?? (((int) ($result->http_status_code ?? 200)) < 400 ? 'healthy' : 'danger'),
            'summary' => $result->summary,
            'run_source' => $result->run_source->value,
            'is_on_demand' => (bool) $result->is_on_demand,
            'http_code' => $result->http_status_code,
            'response_time_ms' => $result->speed,
            'ssl_expiry_date' => $result->ssl_expiry_date?->toISOString(),
            'transport_error_type' => $result->transport_error_type,
            'transport_error_message' => ApiMonitorEvidenceRedactor::redactTransportErrorMessage($result->transport_error_message),
            'transport_error_code' => $result->transport_error_code,
            'checked_at' => $result->created_at?->toISOString(),
            'created_at' => $result->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentHeartbeatPayload(ProjectComponentHeartbeat $heartbeat): array
    {
        return [
            'id' => $heartbeat->id,
            'check_id' => "component:{$heartbeat->project_component_id}",
            'success' => $heartbeat->status === 'healthy',
            'status' => $heartbeat->status ?? 'unknown',
            'summary' => $heartbeat->summary,
            'event' => $heartbeat->event,
            'metrics' => $heartbeat->metrics,
            'run_source' => 'heartbeat',
            'is_on_demand' => false,
            'checked_at' => $heartbeat->observed_at?->toISOString(),
            'observed_at' => $heartbeat->observed_at?->toISOString(),
            'created_at' => $heartbeat->created_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function redactHeaders(array $headers): array
    {
        return collect($headers)
            ->mapWithKeys(fn (mixed $value, string $name): array => [
                $name => $this->isSensitiveHeader($name) ? '[redacted]' : $value,
            ])
            ->all();
    }

    private function isSensitiveHeader(string $name): bool
    {
        return $this->isSensitiveCredentialField($name);
    }

    private function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $sanitizedUrl = preg_replace('/^((?:[a-z][a-z0-9+.-]*:)?\/\/)([^\/?#@]+@)/i', '$1[redacted]@', $url) ?? $url;

        $queryStart = strpos($sanitizedUrl, '?');

        if ($queryStart === false) {
            return $sanitizedUrl;
        }

        $prefix = substr($sanitizedUrl, 0, $queryStart);
        $queryAndFragment = substr($sanitizedUrl, $queryStart + 1);
        $fragment = '';

        $fragmentStart = strpos($queryAndFragment, '#');

        if ($fragmentStart !== false) {
            $fragment = substr($queryAndFragment, $fragmentStart);
            $queryAndFragment = substr($queryAndFragment, 0, $fragmentStart);
        }

        parse_str($queryAndFragment, $query);

        $sanitized = $this->redactQueryParameters($query);

        $rebuiltQuery = http_build_query($sanitized);

        return $prefix.($rebuiltQuery === '' ? '' : '?'.$rebuiltQuery).$fragment;
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @return array<array-key, mixed>
     */
    private function redactQueryParameters(array $query): array
    {
        return collect($query)
            ->mapWithKeys(function (mixed $value, string|int $key): array {
                if ($this->isSensitiveUrlField((string) $key)) {
                    return [$key => '[redacted]'];
                }

                if (is_array($value)) {
                    return [$key => $this->redactQueryParameters($value)];
                }

                return [$key => $value];
            })
            ->all();
    }

    private function isSensitiveUrlField(string $name): bool
    {
        return $this->isSensitiveCredentialField($name);
    }

    private function isSensitiveCredentialField(string $name): bool
    {
        $normalized = strtolower($name);
        $compact = str_replace(['-', '_', ' '], '', $normalized);

        return $compact === 'auth'
            || $compact === 'authentication'
            || str_contains($compact, 'authorization')
            || str_contains($compact, 'authkey')
            || str_contains($compact, 'apikey')
            || str_contains($compact, 'token')
            || str_contains($compact, 'secret')
            || str_contains($compact, 'signature')
            || str_contains($compact, 'password')
            || str_contains($compact, 'credential')
            || str_contains($compact, 'cookie');
    }

    private function isCanonicalNumericId(string|int $value): bool
    {
        if (is_int($value)) {
            return true;
        }

        if (! ctype_digit($value)) {
            return false;
        }

        return (string) ((int) $value) === $value;
    }
}
