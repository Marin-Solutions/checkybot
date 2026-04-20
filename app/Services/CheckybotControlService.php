<?php

namespace App\Services;

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckybotControlService
{
    public function __construct(
        private readonly PackageHealthStatusService $statusService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function me(User $user, ?string $apiKeyName = null): array
    {
        return [
            'authenticated' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'api_key' => [
                'name' => $apiKeyName,
            ],
            'app' => [
                'name' => config('app.name'),
                'url' => config('app.url'),
                'version' => $this->appVersion(),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listProjects(User $user): array
    {
        return $this->projectQuery($user)
            ->withCount([
                'packageManagedApis as checks_count',
                'packageManagedApis as enabled_checks_count' => fn (Builder $query) => $query->where('is_enabled', true),
            ])
            ->latest('updated_at')
            ->get()
            ->map(fn (Project $project): array => $this->projectSummary($project))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getProject(User $user, string|int $projectKey): array
    {
        $project = $this->findProject($user, $projectKey)
            ->loadCount([
                'packageManagedApis as checks_count',
                'packageManagedApis as enabled_checks_count' => fn (Builder $query) => $query->where('is_enabled', true),
            ]);

        return array_merge($this->projectSummary($project), [
            'status_counts' => $this->statusCounts($project),
            'latest_failure' => $this->latestFailures($user, $project, 1)[0] ?? null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listChecks(User $user, string|int $projectKey): array
    {
        $project = $this->findProject($user, $projectKey);

        return $project->packageManagedApis()
            ->with(['assertions', 'results' => fn ($query) => $query->latest()->limit(1)])
            ->orderBy('package_name')
            ->get()
            ->map(fn (MonitorApis $check): array => $this->checkPayload($check))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function upsertCheck(User $user, string|int $projectKey, array $data): array
    {
        $project = $this->findProject($user, $projectKey);

        return DB::transaction(function () use ($project, $data): array {
            $checkKey = $data['key'];
            $check = MonitorApis::withTrashed()
                ->where('project_id', $project->id)
                ->where('source', 'package')
                ->where('package_name', $checkKey)
                ->lockForUpdate()
                ->first();

            $created = false;

            if (! $check instanceof MonitorApis) {
                $check = new MonitorApis;
                $created = true;
            } elseif ($check->trashed()) {
                $check->restore();
            }

            $check->fill([
                'project_id' => $project->id,
                'created_by' => $project->created_by,
                'title' => $data['name'],
                'url' => $this->resolveUrl($project->base_url, $data['url']),
                'http_method' => strtoupper($data['method'] ?? 'GET'),
                'request_path' => $data['url'],
                'data_path' => $this->primaryDataPath($data['assertions'] ?? []),
                'headers' => $data['headers'] ?? [],
                'expected_status' => $data['expected_status'] ?? 200,
                'timeout_seconds' => $data['timeout_seconds'] ?? null,
                'package_schedule' => $data['schedule'] ?? null,
                // Existing stale-check logic reads package_interval; package_schedule preserves the control API contract name.
                'package_interval' => $data['schedule'] ?? null,
                'is_enabled' => $data['enabled'] ?? true,
                'source' => 'package',
                'package_name' => $checkKey,
                'last_synced_at' => now(),
            ]);
            $check->save();

            $this->syncAssertions($check, $data['assertions'] ?? []);
            $check->load(['assertions', 'results' => fn ($query) => $query->latest()->limit(1)]);

            return [
                'created' => $created,
                'check' => $this->checkPayload($check),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function disableCheck(User $user, string|int $projectKey, string $checkKey): array
    {
        $check = $this->findCheck($user, $projectKey, $checkKey);

        $check->forceFill([
            'is_enabled' => false,
            'current_status' => 'unknown',
            'status_summary' => 'Disabled by Checkybot control API.',
            'last_synced_at' => now(),
        ])->save();

        return $this->checkPayload($check->fresh(['assertions']));
    }

    /**
     * @return array<string, mixed>
     */
    public function triggerProjectRun(User $user, string|int $projectKey): array
    {
        $project = $this->findProject($user, $projectKey);
        $checks = $project->packageManagedApis()
            ->where('is_enabled', true)
            ->orderBy('package_name')
            ->get();

        return [
            'project' => $this->projectIdentity($project),
            'status' => $checks->isEmpty() ? 'no_enabled_checks' : 'completed',
            'triggered_at' => now()->toISOString(),
            'checks_run' => $checks->count(),
            'results' => $checks
                ->map(fn (MonitorApis $check): array => $this->runCheck($check))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function triggerCheckRun(User $user, string|int $projectKey, string $checkKey): array
    {
        $check = $this->findCheck($user, $projectKey, $checkKey);

        if (! $check->is_enabled) {
            abort(409, 'Check is disabled. Enable or upsert the check before triggering a run.');
        }

        return $this->runCheck($check);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recentRuns(User $user, ?string $projectKey = null, int $limit = 25): array
    {
        $query = $this->resultQuery($user);

        if ($projectKey !== null) {
            $project = $this->findProject($user, $projectKey);
            $query->whereHas('monitorApi', fn (Builder $monitorQuery) => $monitorQuery->where('project_id', $project->id));
        }

        return $query->latest()
            ->limit(min(max($limit, 1), 100))
            ->get()
            ->map(fn (MonitorApiResult $result): array => $this->resultPayload($result))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestFailures(User $user, ?Project $project = null, int $limit = 25): array
    {
        $query = $this->resultQuery($user)
            ->where(function (Builder $resultQuery): void {
                $resultQuery->where('is_success', false)
                    ->orWhereIn('status', ['warning', 'danger']);
            });

        if ($project instanceof Project) {
            $query->whereHas('monitorApi', fn (Builder $monitorQuery) => $monitorQuery->where('project_id', $project->id));
        }

        return $query->latest()
            ->limit(min(max($limit, 1), 100))
            ->get()
            ->map(fn (MonitorApiResult $result): array => $this->resultPayload($result))
            ->all();
    }

    public function findProject(User $user, string|int $projectKey): Project
    {
        return $this->projectQuery($user)
            ->where(function (Builder $query) use ($projectKey): void {
                $query->where('id', $projectKey)
                    ->orWhere('package_key', $projectKey);
            })
            ->firstOrFail();
    }

    private function findCheck(User $user, string|int $projectKey, string $checkKey): MonitorApis
    {
        $project = $this->findProject($user, $projectKey);

        return $project->packageManagedApis()
            ->with(['assertions', 'results' => fn ($query) => $query->latest()->limit(1)])
            ->where('package_name', $checkKey)
            ->firstOrFail();
    }

    private function projectQuery(User $user): Builder
    {
        return Project::query()->where('created_by', $user->id);
    }

    private function resultQuery(User $user): Builder
    {
        return MonitorApiResult::query()
            ->with('monitorApi.project')
            ->whereHas('monitorApi', fn (Builder $monitorQuery) => $monitorQuery->where('created_by', $user->id));
    }

    /**
     * @return array<string, mixed>
     */
    private function projectSummary(Project $project): array
    {
        return array_merge($this->projectIdentity($project), [
            'name' => $project->name,
            'environment' => $project->environment,
            'base_url' => $project->base_url,
            'repository' => $project->repository,
            'checks_count' => (int) ($project->checks_count ?? $project->packageManagedApis()->count()),
            'enabled_checks_count' => (int) ($project->enabled_checks_count ?? $project->packageManagedApis()->where('is_enabled', true)->count()),
            'last_synced_at' => $project->last_synced_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectIdentity(Project $project): array
    {
        return [
            'id' => $project->id,
            'key' => $project->package_key,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(Project $project): array
    {
        return $project->packageManagedApis()
            ->selectRaw("coalesce(current_status, 'unknown') as status, count(*) as aggregate")
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPayload(MonitorApis $check): array
    {
        $latestResult = $check->relationLoaded('results') ? $check->results->first() : $check->results()->latest()->first();

        return [
            'id' => $check->id,
            'key' => $check->package_name,
            'type' => 'api',
            'name' => $check->title,
            'url' => $check->url,
            'method' => $check->http_method,
            'request_path' => $check->request_path,
            'expected_status' => $check->expected_status,
            'timeout_seconds' => $check->timeout_seconds,
            'schedule' => $check->package_schedule,
            'enabled' => $check->is_enabled,
            'status' => $check->current_status ?? 'unknown',
            'status_summary' => $check->status_summary,
            'last_heartbeat_at' => $check->last_heartbeat_at?->toISOString(),
            'stale_at' => $check->stale_at?->toISOString(),
            'headers' => $this->redactHeaders($check->headers),
            'assertions' => $this->assertionsPayload($check->assertions),
            'latest_result' => $latestResult instanceof MonitorApiResult ? $this->resultPayload($latestResult) : null,
            'updated_at' => $check->updated_at?->toISOString(),
        ];
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
                'path' => $assertion->data_path,
                'type' => $assertion->assertion_type,
                'expected_type' => $assertion->expected_type,
                'comparison_operator' => $assertion->comparison_operator,
                'expected_value' => $assertion->expected_value,
                'regex_pattern' => $assertion->regex_pattern,
                'sort_order' => $assertion->sort_order,
                'active' => $assertion->is_active,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function resultPayload(MonitorApiResult $result): array
    {
        $check = $result->monitorApi;
        $project = $check?->project;

        return [
            'id' => $result->id,
            'project' => $project instanceof Project ? $this->projectIdentity($project) : null,
            'check' => $check instanceof MonitorApis ? [
                'id' => $check->id,
                'key' => $check->package_name,
                'name' => $check->title,
            ] : null,
            'success' => $result->is_success,
            'status' => $result->status ?? ($result->is_success ? 'healthy' : 'danger'),
            'summary' => $result->summary,
            'http_code' => $result->http_code,
            'response_time_ms' => $result->response_time_ms,
            'failed_assertions' => $result->failed_assertions,
            'created_at' => $result->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runCheck(MonitorApis $check): array
    {
        $startTime = microtime(true);
        $rawResult = MonitorApis::testApi([
            'id' => $check->id,
            'url' => $check->url,
            'data_path' => $check->data_path,
            'headers' => $check->headers,
            'title' => $check->title,
        ]);

        $status = $this->statusService->apiStatusFromResult($rawResult);
        $summary = $this->statusService->summaryForApi($rawResult);
        $result = MonitorApiResult::recordResult($check, $rawResult, $startTime, $status, $summary);

        $check->forceFill([
            'current_status' => $status,
            'last_heartbeat_at' => now(),
            'stale_at' => null,
            'status_summary' => $summary,
        ])->save();

        return [
            'check' => [
                'id' => $check->id,
                'key' => $check->package_name,
                'name' => $check->title,
            ],
            'result' => $this->resultPayload($result->load('monitorApi.project')),
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
        $normalized = strtolower($name);

        return $normalized === 'authorization'
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'api-key')
            || str_contains($normalized, 'apikey')
            || str_contains($normalized, 'auth-key');
    }

    private function resolveUrl(?string $baseUrl, string $url): string
    {
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        if (blank($baseUrl)) {
            throw ValidationException::withMessages([
                'url' => ['Relative check URLs require the project to have a base_url. Provide an absolute URL or set the project base_url.'],
            ]);
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

    /**
     * @param  array<int, array<string, mixed>>  $assertions
     */
    private function syncAssertions(MonitorApis $check, array $assertions): void
    {
        MonitorApiAssertion::query()
            ->where('monitor_api_id', $check->id)
            ->delete();

        if ($assertions === []) {
            return;
        }

        $timestamp = now();

        MonitorApiAssertion::query()->insert(
            collect($assertions)
                ->values()
                ->map(fn (array $assertion, int $index): array => [
                    'monitor_api_id' => $check->id,
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
                    'sort_order' => $assertion['sort_order'] ?? ($index + 1),
                    'is_active' => $assertion['active'] ?? true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])
                ->all()
        );
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

    private function appVersion(): string
    {
        $installed = base_path('composer.lock');

        return is_file($installed)
            ? substr(hash_file('sha256', $installed), 0, 12)
            : 'unknown';
    }
}
