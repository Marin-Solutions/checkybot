<?php

namespace App\Services;

use App\Enums\RunSource;
use App\Jobs\LogUptimeSslJob;
use App\Jobs\RunApiMonitorDiagnosticJob;
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
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckybotControlService
{
    public function __construct(
        private readonly ApiMonitorExecutionService $executionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function me(User $user, ?string $apiKeyName = null): array
    {
        return [
            'authenticated' => true,
            'server_time' => now()->toISOString(),
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
                'packageManagedApis as disabled_checks_count' => fn (Builder $query) => $query->where('is_enabled', false),
                'packageManagedWebsites as website_checks_count',
                'packageManagedWebsites as enabled_website_checks_count' => fn (Builder $query) => $this->activeWebsiteCheckConstraint($query),
                'packageManagedWebsites as disabled_website_checks_count' => fn (Builder $query) => $this->disabledWebsiteCheckConstraint($query),
                'components as components_count',
                'activeComponents as active_components_count',
                'components as archived_components_count' => fn (Builder $query) => $query->where('is_archived', true),
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
                'packageManagedApis as disabled_checks_count' => fn (Builder $query) => $query->where('is_enabled', false),
                'packageManagedWebsites as website_checks_count',
                'packageManagedWebsites as enabled_website_checks_count' => fn (Builder $query) => $this->activeWebsiteCheckConstraint($query),
                'packageManagedWebsites as disabled_website_checks_count' => fn (Builder $query) => $this->disabledWebsiteCheckConstraint($query),
                'components as components_count',
                'activeComponents as active_components_count',
                'components as archived_components_count' => fn (Builder $query) => $query->where('is_archived', true),
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

        $apiChecks = $project->packageManagedApis()
            ->with(['assertions', 'latestResult'])
            ->orderBy('package_name')
            ->get()
            ->map(fn (MonitorApis $check): array => $this->checkPayload($check));

        $websiteChecks = $project->packageManagedWebsites()
            ->with('latestLogHistory')
            ->orderBy('package_name')
            ->get()
            ->map(fn (Website $website): array => $this->websiteCheckPayload($website));

        $componentChecks = $project->components()
            ->with('latestHeartbeat')
            ->orderBy('name')
            ->get()
            ->map(fn (ProjectComponent $component): array => $this->componentCheckPayload($component));

        return $apiChecks
            ->concat($websiteChecks)
            ->concat($componentChecks)
            ->sortBy([
                ['key', 'asc'],
                ['type', 'asc'],
            ])
            ->values()
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

            $schedule = $this->effectiveSchedule($data['schedule'] ?? null, $check);
            $normalizedSchedule = IntervalParser::normalizeOrFail($schedule, 'schedule');

            $check->fill([
                'project_id' => $project->id,
                'created_by' => $project->created_by,
                'title' => $data['name'],
                'url' => $this->resolveUrl($project->base_url, $data['url']),
                'http_method' => strtoupper($data['method'] ?? 'GET'),
                'request_path' => $data['url'],
                'data_path' => $this->primaryDataPath($data['assertions'] ?? []),
                'headers' => $data['headers'] ?? [],
                'request_body_type' => $data['request_body_type'] ?? null,
                'request_body' => $data['request_body'] ?? null,
                'expected_status' => $data['expected_status'] ?? 200,
                'timeout_seconds' => $data['timeout_seconds'] ?? null,
                'package_schedule' => $schedule,
                // Stale detection reads package_interval, so persist the compact normalized form there.
                'package_interval' => $normalizedSchedule,
                'is_enabled' => $data['enabled'] ?? true,
                'source' => 'package',
                'package_name' => $checkKey,
                'last_synced_at' => now(),
            ]);
            $check->save();

            $this->syncAssertions($check, $data['assertions'] ?? []);
            $check->load(['assertions', 'latestResult']);

            return [
                'created' => $created,
                'check' => $this->checkPayload($check),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function disableCheck(User $user, string|int $projectKey, string $checkKey, ?string $checkType = null): array
    {
        $check = $this->findControllableCheck($user, $projectKey, $checkKey, $checkType);

        if ($check instanceof ProjectComponent) {
            $check->forceFill(ProjectComponent::disabledHealthAttributes('Disabled by Checkybot control API.') + [
                'is_archived' => true,
                'project_paused_monitoring' => false,
                'archived_at' => now(),
                'archive_reason' => ProjectComponent::ARCHIVE_REASON_USER,
            ])->save();

            return $this->componentCheckPayload($check->fresh('latestHeartbeat'));
        }

        if ($check instanceof Website) {
            $check->forceFill([
                'uptime_check' => false,
                'ssl_check' => false,
                'current_status' => 'unknown',
                'status_summary' => 'Disabled by Checkybot control API.',
                'last_heartbeat_at' => null,
                'stale_at' => null,
                'diagnostic_queued_at' => null,
                'last_synced_at' => now(),
            ])->save();

            return $this->websiteCheckPayload($check->fresh(['latestLogHistory']));
        }

        $check->forceFill(MonitorApis::disabledHealthAttributes('Disabled by Checkybot control API.') + [
            'is_enabled' => false,
            'last_synced_at' => now(),
        ])->save();

        return $this->checkPayload($check->fresh(['assertions', 'latestResult']));
    }

    /**
     * @return array<string, mixed>
     */
    public function triggerProjectRun(User $user, string|int $projectKey): array
    {
        $project = $this->findProject($user, $projectKey);
        $apiChecks = $project->packageManagedApis()
            ->where('is_enabled', true)
            ->orderBy('package_name')
            ->get();
        $websiteChecks = $project->packageManagedWebsites()
            ->where(function (Builder $query): void {
                $query->where('uptime_check', true)
                    ->orWhere('ssl_check', true);
            })
            ->orderBy('package_name')
            ->get();
        $jobs = $apiChecks
            ->map(fn (MonitorApis $check): RunApiMonitorDiagnosticJob => new RunApiMonitorDiagnosticJob($check->withoutRelations()))
            ->merge($websiteChecks->map(fn (Website $website): LogUptimeSslJob => new LogUptimeSslJob($website->withoutRelations(), onDemand: true)));

        if ($jobs->isEmpty()) {
            return [
                'project' => $this->projectIdentity($project),
                'status' => 'no_enabled_checks',
                'triggered_at' => now()->toISOString(),
                'checks_queued' => 0,
                'run_batch' => null,
            ];
        }

        $queuedAt = now();

        MonitorApis::query()
            ->whereKey($apiChecks->modelKeys())
            ->update(['diagnostic_queued_at' => $queuedAt]);

        Website::query()
            ->whereKey($websiteChecks->modelKeys())
            ->update(['diagnostic_queued_at' => $queuedAt]);

        $batch = Bus::batch($jobs)
            ->name($this->controlProjectRunBatchName($project))
            ->withOption('checkybot_control', [
                'project_id' => $project->id,
                'user_id' => $project->created_by,
            ])
            ->allowFailures()
            ->dispatch();

        return [
            'project' => $this->projectIdentity($project),
            'status' => 'queued',
            'triggered_at' => now()->toISOString(),
            'checks_queued' => $jobs->count(),
            'run_batch' => $this->runBatchPayload($batch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectRunBatch(User $user, string|int $projectKey, string $batchId): array
    {
        $project = $this->findProject($user, $projectKey);
        $batch = Bus::findBatch($batchId);

        if (! $batch instanceof Batch
            || (int) Arr::get($batch->options, 'checkybot_control.project_id') !== $project->id
            || (int) Arr::get($batch->options, 'checkybot_control.user_id') !== $user->id) {
            abort(404, 'Project run batch not found.');
        }

        return [
            'project' => $this->projectIdentity($project),
            'run_batch' => $this->runBatchPayload($batch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function triggerCheckRun(User $user, string|int $projectKey, string $checkKey, ?string $checkType = null): array
    {
        $check = $this->findRunnableCheck($user, $projectKey, $checkKey, $checkType);

        if ($check instanceof Website) {
            if (! $this->websiteHasEnabledStatusCheck($check)) {
                abort(409, 'Check is disabled. Enable uptime or SSL checks before triggering a run.');
            }

            return $this->queueWebsiteCheck($check);
        }

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
        $limit = min(max($limit, 1), 100);
        $project = $projectKey !== null ? $this->findProject($user, $projectKey) : null;

        return collect([
            ...$this->recentApiRuns($user, $project, $limit),
            ...$this->recentWebsiteRuns($user, $project, $limit),
        ])
            ->sortByDesc(fn (array $run): string => (string) ($run['checked_at'] ?? $run['created_at'] ?? ''))
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentApiRuns(User $user, ?Project $project, int $limit): array
    {
        $query = $this->resultQuery($user);

        if ($project instanceof Project) {
            $query->whereHas('monitorApi', fn (Builder $monitorQuery) => $monitorQuery->where('project_id', $project->id));
        }

        return $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (MonitorApiResult $result): array => $this->resultPayload($result))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentWebsiteRuns(User $user, ?Project $project, int $limit): array
    {
        $query = $this->websiteResultQuery($user);

        if ($project instanceof Project) {
            $query->whereHas('website', fn (Builder $websiteQuery) => $websiteQuery->where('project_id', $project->id));
        }

        return $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (WebsiteLogHistory $result): array => $this->websiteResultPayload($result))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestFailures(User $user, ?Project $project = null, int $limit = 25): array
    {
        $limit = min(max($limit, 1), 100);

        return collect([
            ...$this->latestApiFailures($user, $project, $limit),
            ...$this->latestWebsiteFailures($user, $project, $limit),
            ...$this->latestComponentFailures($user, $project, $limit),
        ])
            ->sortByDesc(fn (array $failure): string => (string) ($failure['checked_at'] ?? $failure['created_at'] ?? ''))
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestApiFailures(User $user, ?Project $project, int $limit): array
    {
        $query = $this->resultQuery($user)
            ->scheduled()
            ->whereHas('monitorApi', function (Builder $monitorQuery): void {
                $monitorQuery->where('is_enabled', true)
                    ->whereIn('current_status', ['warning', 'danger']);
            })
            ->whereNotExists(function ($subQuery): void {
                $subQuery->selectRaw('1')
                    ->from('monitor_api_results as newer_results')
                    ->whereColumn('newer_results.monitor_api_id', 'monitor_api_results.monitor_api_id')
                    ->where('newer_results.is_on_demand', false)
                    ->where(function ($newerResultQuery): void {
                        $newerResultQuery->whereColumn('newer_results.created_at', '>', 'monitor_api_results.created_at')
                            ->orWhere(function ($sameTimestampQuery): void {
                                $sameTimestampQuery->whereColumn('newer_results.created_at', 'monitor_api_results.created_at')
                                    ->whereColumn('newer_results.id', '>', 'monitor_api_results.id');
                            });
                    });
            })
            ->where(function (Builder $resultQuery): void {
                $resultQuery->where('is_success', false)
                    ->orWhereIn('status', ['warning', 'danger']);
            });

        if ($project instanceof Project) {
            $query->whereHas('monitorApi', fn (Builder $monitorQuery) => $monitorQuery->where('project_id', $project->id));
        }

        return $query->latest()
            ->limit($limit)
            ->get()
            ->map(fn (MonitorApiResult $result): array => $this->resultPayload($result))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestWebsiteFailures(User $user, ?Project $project, int $limit): array
    {
        $query = WebsiteLogHistory::query()
            ->with('website.project')
            ->where('is_on_demand', false)
            ->whereHas('website', function (Builder $websiteQuery) use ($user, $project): void {
                $websiteQuery->where('created_by', $user->id)
                    ->whereIn('current_status', ['warning', 'danger'])
                    ->where(function (Builder $monitoringQuery): void {
                        $monitoringQuery->where('uptime_check', true)
                            ->orWhere('ssl_check', true);
                    });

                if ($project instanceof Project) {
                    $websiteQuery->where('project_id', $project->id);
                }
            })
            ->whereNotExists(function ($subQuery): void {
                $subQuery->selectRaw('1')
                    ->from('website_log_history as newer_logs')
                    ->whereColumn('newer_logs.website_id', 'website_log_history.website_id')
                    ->where('newer_logs.is_on_demand', false)
                    ->where(function ($newerLogQuery): void {
                        $newerLogQuery->whereColumn('newer_logs.created_at', '>', 'website_log_history.created_at')
                            ->orWhere(function ($sameTimestampQuery): void {
                                $sameTimestampQuery->whereColumn('newer_logs.created_at', 'website_log_history.created_at')
                                    ->whereColumn('newer_logs.id', '>', 'website_log_history.id');
                            });
                    });
            })
            ->where(function (Builder $logQuery): void {
                $logQuery->whereIn('status', ['warning', 'danger'])
                    ->orWhere('http_status_code', '>=', 400)
                    ->orWhereNotNull('transport_error_type');
            });

        return $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (WebsiteLogHistory $result): array => $this->websiteResultPayload($result))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestComponentFailures(User $user, ?Project $project, int $limit): array
    {
        $query = ProjectComponentHeartbeat::query()
            ->with('component.project')
            ->whereHas('component', function (Builder $componentQuery) use ($user, $project): void {
                $componentQuery->where('created_by', $user->id)
                    ->where('is_archived', false)
                    ->whereIn('current_status', ['warning', 'danger']);

                if ($project instanceof Project) {
                    $componentQuery->where('project_id', $project->id);
                }
            })
            ->whereNotExists(function ($subQuery): void {
                $subQuery->selectRaw('1')
                    ->from('project_component_heartbeats as newer_heartbeats')
                    ->whereColumn('newer_heartbeats.project_component_id', 'project_component_heartbeats.project_component_id')
                    ->where(function ($newerHeartbeatQuery): void {
                        $newerHeartbeatQuery->whereColumn('newer_heartbeats.observed_at', '>', 'project_component_heartbeats.observed_at')
                            ->orWhere(function ($sameTimestampQuery): void {
                                $sameTimestampQuery->whereColumn('newer_heartbeats.observed_at', 'project_component_heartbeats.observed_at')
                                    ->whereColumn('newer_heartbeats.id', '>', 'project_component_heartbeats.id');
                            });
                    });
            })
            ->whereIn('status', ['warning', 'danger']);

        return $query->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ProjectComponentHeartbeat $heartbeat): array => $this->componentHeartbeatPayload($heartbeat))
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

    private function findControllableCheck(User $user, string|int $projectKey, string $checkKey, ?string $checkType): MonitorApis|Website|ProjectComponent
    {
        $project = $this->findProject($user, $projectKey);

        if ($checkType === 'api') {
            return $project->packageManagedApis()
                ->with(['assertions', 'latestResult'])
                ->where('package_name', $checkKey)
                ->firstOrFail();
        }

        if ($checkType === 'website') {
            return $project->packageManagedWebsites()
                ->with('latestLogHistory')
                ->where('package_name', $checkKey)
                ->firstOrFail();
        }

        if ($checkType === 'component') {
            return $project->components()
                ->with('latestHeartbeat')
                ->where('name', $checkKey)
                ->firstOrFail();
        }

        $apiCheck = $project->packageManagedApis()
            ->with(['assertions', 'latestResult'])
            ->where('package_name', $checkKey)
            ->first();

        $websiteCheck = $project->packageManagedWebsites()
            ->with('latestLogHistory')
            ->where('package_name', $checkKey)
            ->first();

        $componentCheck = $project->components()
            ->with('latestHeartbeat')
            ->where('name', $checkKey)
            ->first();

        $matches = collect([$apiCheck, $websiteCheck, $componentCheck])
            ->filter()
            ->count();

        if ($matches > 1) {
            abort(409, 'Check key matches multiple check types. Pass type=api, type=website, or type=component to disable a specific check.');
        }

        if ($apiCheck instanceof MonitorApis) {
            return $apiCheck;
        }

        if ($websiteCheck instanceof Website) {
            return $websiteCheck;
        }

        if ($componentCheck instanceof ProjectComponent) {
            return $componentCheck;
        }

        abort(404, 'Check not found.');
    }

    private function findRunnableCheck(User $user, string|int $projectKey, string $checkKey, ?string $checkType): MonitorApis|Website
    {
        $project = $this->findProject($user, $projectKey);

        if ($checkType === 'api') {
            return $project->packageManagedApis()
                ->with(['assertions', 'latestResult'])
                ->where('package_name', $checkKey)
                ->firstOrFail();
        }

        if ($checkType === 'website') {
            return $project->packageManagedWebsites()
                ->where('package_name', $checkKey)
                ->firstOrFail();
        }

        $apiCheck = $project->packageManagedApis()
            ->with(['assertions', 'latestResult'])
            ->where('package_name', $checkKey)
            ->first();

        $websiteCheck = $project->packageManagedWebsites()
            ->where('package_name', $checkKey)
            ->first();

        if ($apiCheck instanceof MonitorApis && $websiteCheck instanceof Website) {
            abort(409, 'Check key matches multiple runnable check types. Pass type=api or type=website to trigger a specific check run.');
        }

        if ($apiCheck instanceof MonitorApis) {
            return $apiCheck;
        }

        if ($websiteCheck instanceof Website) {
            return $websiteCheck;
        }

        abort(404, 'Check not found.');
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

    private function websiteResultQuery(User $user): Builder
    {
        return WebsiteLogHistory::query()
            ->with('website.project')
            ->whereHas('website', fn (Builder $websiteQuery) => $websiteQuery->where('created_by', $user->id));
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
            'checks_count' => $this->totalPackageChecksCount($project),
            'enabled_checks_count' => $this->enabledPackageChecksCount($project),
            'disabled_checks_count' => $this->disabledPackageChecksCount($project),
            'components_count' => $this->totalComponentsCount($project),
            'active_components_count' => $this->activeComponentsCount($project),
            'archived_components_count' => $this->archivedComponentsCount($project),
            'created_at' => $project->created_at?->toISOString(),
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

    private function totalPackageChecksCount(Project $project): int
    {
        return (int) ($project->checks_count ?? $project->packageManagedApis()->count())
            + (int) ($project->website_checks_count ?? $project->packageManagedWebsites()->count())
            + $this->totalComponentsCount($project);
    }

    private function enabledPackageChecksCount(Project $project): int
    {
        return (int) ($project->enabled_checks_count ?? $project->packageManagedApis()->where('is_enabled', true)->count())
            + (int) ($project->enabled_website_checks_count ?? $project->packageManagedWebsites()
                ->where(fn (Builder $query) => $this->activeWebsiteCheckConstraint($query))
                ->count())
            + $this->activeComponentsCount($project);
    }

    private function disabledPackageChecksCount(Project $project): int
    {
        return (int) ($project->disabled_checks_count ?? $project->packageManagedApis()->where('is_enabled', false)->count())
            + (int) ($project->disabled_website_checks_count ?? $project->packageManagedWebsites()
                ->where(fn (Builder $query) => $this->disabledWebsiteCheckConstraint($query))
                ->count())
            + $this->archivedComponentsCount($project);
    }

    private function totalComponentsCount(Project $project): int
    {
        return (int) ($project->components_count ?? $project->components()->count());
    }

    private function activeComponentsCount(Project $project): int
    {
        return (int) ($project->active_components_count ?? $project->activeComponents()->count());
    }

    private function archivedComponentsCount(Project $project): int
    {
        return (int) ($project->archived_components_count ?? $project->components()->where('is_archived', true)->count());
    }

    private function activeWebsiteCheckConstraint(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('uptime_check', true)
                ->orWhere('ssl_check', true);
        });
    }

    private function disabledWebsiteCheckConstraint(Builder $query): Builder
    {
        return $query->where('uptime_check', false)
            ->where('ssl_check', false);
    }

    private function websiteHasEnabledStatusCheck(Website $website): bool
    {
        return (bool) $website->uptime_check || (bool) $website->ssl_check;
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(Project $project): array
    {
        $counts = $project->packageManagedApis()
            ->where('is_enabled', true)
            ->selectRaw("coalesce(current_status, 'unknown') as status, count(*) as aggregate")
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $websiteCounts = $project->packageManagedWebsites()
            ->where(fn (Builder $query) => $this->activeWebsiteCheckConstraint($query))
            ->selectRaw("coalesce(current_status, 'unknown') as status, count(*) as aggregate")
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        foreach ($websiteCounts as $status => $count) {
            $counts[$status] = (int) ($counts[$status] ?? 0) + $count;
        }

        $componentCounts = $project->activeComponents()
            ->get(['current_status', 'is_stale', 'last_heartbeat_at'])
            ->map(fn (ProjectComponent $component): string => $this->componentStatusBucket($component))
            ->countBy()
            ->map(fn ($count): int => (int) $count)
            ->all();

        foreach ($componentCounts as $status => $count) {
            $counts[$status] = (int) ($counts[$status] ?? 0) + $count;
        }

        $counts['disabled'] = $this->disabledPackageChecksCount($project);

        return $counts;
    }

    private function componentStatusBucket(ProjectComponent $component): string
    {
        if ((bool) $component->is_stale) {
            return 'danger';
        }

        if (in_array($component->current_status, ['warning', 'danger'], true)) {
            return $component->current_status;
        }

        return $component->current_status === 'healthy' && $component->last_heartbeat_at !== null
            ? 'healthy'
            : 'unknown';
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPayload(MonitorApis $check): array
    {
        $latestResult = $check->relationLoaded('latestResult') ? $check->latestResult : $check->results()->latest()->first();

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
            'supports_run' => true,
            'status' => $check->current_status ?? 'unknown',
            'status_summary' => $check->status_summary,
            'last_synced_at' => $check->last_synced_at?->toISOString(),
            'last_heartbeat_at' => $check->last_heartbeat_at?->toISOString(),
            'stale_at' => $check->stale_at?->toISOString(),
            'headers' => ApiMonitorEvidenceRedactor::redactHeaders($check->headers),
            'request_body_type' => $check->request_body_type,
            'has_request_body' => $check->hasRequestBody(),
            'assertions' => $this->assertionsPayload($check->assertions),
            'latest_result' => $latestResult instanceof MonitorApiResult ? $this->resultPayload($latestResult) : null,
            'updated_at' => $check->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteCheckPayload(Website $website): array
    {
        $latestResult = $website->relationLoaded('latestLogHistory')
            ? $website->latestLogHistory
            : $website->latestLogHistory()->first();

        if ($latestResult instanceof WebsiteLogHistory) {
            $latestResult->setRelation('website', $website);
        }

        return [
            'id' => $website->id,
            'key' => $website->package_name,
            'type' => 'website',
            'check_types' => $this->websiteCheckTypes($website),
            'name' => $website->name,
            'url' => $website->url,
            'method' => 'GET',
            'request_path' => $website->url,
            'expected_status' => null,
            'timeout_seconds' => null,
            'schedule' => $website->package_interval,
            'enabled' => (bool) $website->uptime_check || (bool) $website->ssl_check,
            'supports_run' => true,
            'status' => $website->current_status ?? 'unknown',
            'status_summary' => $website->status_summary,
            'last_synced_at' => $website->last_synced_at?->toISOString(),
            'last_heartbeat_at' => $website->last_heartbeat_at?->toISOString(),
            'stale_at' => $website->stale_at?->toISOString(),
            'headers' => [],
            'request_body_type' => null,
            'has_request_body' => false,
            'assertions' => [],
            'latest_result' => $latestResult instanceof WebsiteLogHistory ? $this->websiteResultPayload($latestResult) : null,
            'updated_at' => $website->updated_at?->toISOString(),
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

        if ($latestHeartbeat instanceof ProjectComponentHeartbeat) {
            $latestHeartbeat->setRelation('component', $component);
        }

        $deliveryState = ProjectComponentDeliveryState::value($component);

        return [
            'id' => $component->id,
            'key' => $component->name,
            'type' => 'component',
            'name' => $component->name,
            'url' => null,
            'method' => null,
            'request_path' => null,
            'expected_status' => null,
            'timeout_seconds' => null,
            'schedule' => $component->declared_interval,
            'declared_interval' => $component->declared_interval,
            'interval_minutes' => $component->interval_minutes,
            'enabled' => ! (bool) $component->is_archived,
            'supports_run' => false,
            'status' => $this->componentStatusBucket($component),
            'reported_status' => $component->last_reported_status,
            'status_summary' => $component->summary,
            'delivery_state' => $deliveryState,
            'delivery_state_label' => ProjectComponentDeliveryState::label($component),
            'is_stale' => (bool) $component->is_stale,
            'is_archived' => (bool) $component->is_archived,
            'last_synced_at' => null,
            'last_heartbeat_at' => $component->last_heartbeat_at?->toISOString(),
            'stale_at' => $component->stale_detected_at?->toISOString(),
            'stale_detected_at' => $component->stale_detected_at?->toISOString(),
            'stale_threshold_at' => $this->componentStaleThresholdAt($component),
            'silenced_until' => $component->silenced_until?->toISOString(),
            'metrics' => $component->metrics,
            'headers' => [],
            'request_body_type' => null,
            'has_request_body' => false,
            'assertions' => [],
            'latest_result' => $latestHeartbeat instanceof ProjectComponentHeartbeat ? $this->componentHeartbeatPayload($latestHeartbeat) : null,
            'updated_at' => $component->updated_at?->toISOString(),
        ];
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
     * @return array<int, string>
     */
    private function websiteCheckTypes(Website $website): array
    {
        return collect([
            $website->uptime_check ? 'uptime' : null,
            $website->ssl_check ? 'ssl' : null,
        ])
            ->filter()
            ->values()
            ->all();
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
                'type' => 'api',
                'name' => $check->title,
            ] : null,
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
     * @return array<string, mixed>
     */
    private function websiteResultPayload(WebsiteLogHistory $result): array
    {
        $website = $result->website;
        $project = $website?->project;
        $status = $result->status ?? (((int) ($result->http_status_code ?? 200)) < 400 ? 'healthy' : 'danger');

        return [
            'id' => $result->id,
            'project' => $project instanceof Project ? $this->projectIdentity($project) : null,
            'check' => $website instanceof Website ? [
                'id' => $website->id,
                'key' => $website->package_name ?? "website-{$website->id}",
                'type' => 'website',
                'name' => $website->name,
                'url' => $website->url,
            ] : null,
            'success' => in_array($status, ['healthy', null], true)
                && ((int) ($result->http_status_code ?? 200)) < 400,
            'status' => $status,
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
        $component = $heartbeat->component;
        $project = $component?->project;

        return [
            'id' => $heartbeat->id,
            'project' => $project instanceof Project ? $this->projectIdentity($project) : null,
            'check' => $component instanceof ProjectComponent ? [
                'id' => $component->id,
                'key' => $component->name,
                'type' => 'component',
                'name' => $component->name,
            ] : null,
            'success' => $heartbeat->status === 'healthy',
            'status' => $heartbeat->status ?? 'unknown',
            'summary' => $heartbeat->summary,
            'event' => $heartbeat->event,
            'metrics' => $heartbeat->metrics,
            'run_source' => 'heartbeat',
            'is_on_demand' => false,
            'checked_at' => $heartbeat->observed_at?->toISOString(),
            'created_at' => $heartbeat->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runCheck(MonitorApis $check): array
    {
        $execution = $this->executionService->execute($check, onDemand: true);
        /** @var MonitorApiResult $result */
        $result = $execution['result'];

        return [
            'check' => [
                'id' => $check->id,
                'key' => $check->package_name,
                'type' => 'api',
                'name' => $check->title,
            ],
            'result' => $this->resultPayload($result->load('monitorApi.project')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueWebsiteCheck(Website $website): array
    {
        $website->refresh();

        if ($website->hasQueuedDiagnostic()) {
            return $this->queuedWebsiteCheckPayload($website, false);
        }

        $queuedAt = now();

        $website->forceFill([
            'diagnostic_queued_at' => $queuedAt,
        ])->save();

        try {
            LogUptimeSslJob::dispatch($website->withoutRelations(), onDemand: true);
        } catch (\Throwable $e) {
            $website->forceFill([
                'diagnostic_queued_at' => null,
            ])->save();

            Log::error('Control API website diagnostic dispatch failed', [
                'website_id' => $website->id,
                'project_id' => $website->project_id,
                'package_name' => $website->package_name,
                'exception' => $e,
            ]);

            throw $e;
        }

        return $this->queuedWebsiteCheckPayload($website->fresh(['latestLogHistory', 'latestDiagnosticLogHistory']), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function queuedWebsiteCheckPayload(Website $website, bool $queued): array
    {
        return [
            'status' => 'queued',
            'queued' => $queued,
            'queued_at' => $website->diagnostic_queued_at?->toISOString(),
            'run_source' => RunSource::OnDemand->value,
            'check' => $this->websiteCheckPayload($website),
            'result' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runBatchPayload(Batch $batch): array
    {
        return [
            'id' => $batch->id,
            'status' => $this->runBatchStatus($batch),
            'name' => $batch->name,
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
            'failed_jobs' => $batch->failedJobs,
            'created_at' => $batch->createdAt?->toISOString(),
            'finished_at' => $batch->finishedAt?->toISOString(),
        ];
    }

    private function runBatchStatus(Batch $batch): string
    {
        if ($batch->cancelled()) {
            return 'cancelled';
        }

        if ($batch->finished()) {
            return 'finished';
        }

        return $batch->pendingJobs < $batch->totalJobs ? 'running' : 'pending';
    }

    private function controlProjectRunBatchName(Project $project): string
    {
        return "Control project run: {$project->package_key}";
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

    private function effectiveSchedule(mixed $schedule, MonitorApis $check): string
    {
        if (is_string($schedule) && filled($schedule)) {
            return $schedule;
        }

        if ($schedule !== null && ! is_string($schedule)) {
            throw ValidationException::withMessages([
                'schedule' => ['The schedule format is invalid. Use format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}.'],
            ]);
        }

        foreach ([$check->package_schedule, $check->package_interval] as $existingSchedule) {
            if (is_string($existingSchedule) && filled($existingSchedule) && IntervalParser::isValid($existingSchedule)) {
                return $existingSchedule;
            }
        }

        return IntervalParser::DEFAULT_API_INTERVAL;
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
