<?php

namespace App\Services;

use App\Console\Commands\LogJobCheckUptimeSsl;
use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\RunSource;
use App\Jobs\LogUptimeSslJob;
use App\Jobs\RunApiMonitorDiagnosticJob;
use App\Models\MonitorApiAssertion;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Support\ApiMonitorEvidenceRedactor;
use App\Support\ProjectComponentDeliveryState;
use App\Support\ScheduledFailureStreak;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckybotControlService
{
    private const CURRENT_ISSUE_CAUSES = [
        'timeout',
        'dns',
        'http_4xx',
        'http_5xx',
        'assertion',
        'stale_setup',
    ];

    public function __construct(
        private readonly ApiMonitorExecutionService $executionService,
        private readonly HealthEventNotificationService $notificationService,
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
     * @param  array<string, mixed>  $data
     * @return array{created: bool, project: array<string, mixed>}
     */
    public function createProject(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $identityEndpoint = $data['identity_endpoint'] ?? $data['base_url'];
            $project = $this->projectQuery($user)
                ->where('environment', $data['environment'])
                ->where('package_key', $data['key'])
                ->lockForUpdate()
                ->first();

            if (! $project instanceof Project) {
                $project = $this->projectQuery($user)
                    ->where('environment', $data['environment'])
                    ->where('identity_endpoint', $identityEndpoint)
                    ->lockForUpdate()
                    ->first();
            }

            $created = false;

            if (! $project instanceof Project) {
                $project = new Project([
                    'created_by' => $user->id,
                    'token' => hash('sha256', (string) Str::uuid()),
                ]);
                $created = true;
            }

            $this->validateProjectIdentityDoesNotConflict($user, $project, $data['environment'], $data['key'], $identityEndpoint);

            $project->fill([
                'package_key' => $data['key'],
                'name' => $data['name'],
                'environment' => $data['environment'],
                'base_url' => $data['base_url'],
                'identity_endpoint' => $identityEndpoint,
                'repository' => $data['repository'] ?? $project->repository,
                'group' => $data['group'] ?? $project->group,
                'technology' => $data['technology'] ?? $project->technology,
                'package_version' => $data['package_version'] ?? $project->package_version,
            ]);

            $project->save();

            $project->loadCount([
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

            return [
                'created' => $created,
                'project' => $this->projectSummary($project),
            ];
        });
    }

    private function validateProjectIdentityDoesNotConflict(
        User $user,
        Project $project,
        string $environment,
        string $packageKey,
        string $identityEndpoint,
    ): void {
        $conflictingProject = $this->projectQuery($user)
            ->where('environment', $environment)
            ->whereKeyNot($project->getKey())
            ->where(function (Builder $query) use ($packageKey, $identityEndpoint): void {
                $query->where('package_key', $packageKey)
                    ->orWhere('identity_endpoint', $identityEndpoint);
            })
            ->lockForUpdate()
            ->first();

        if (! $conflictingProject instanceof Project) {
            return;
        }

        $errors = [];

        if ($conflictingProject->package_key === $packageKey) {
            $errors['key'] = ['A project with this key already exists for this environment.'];
        }

        if ($conflictingProject->identity_endpoint === $identityEndpoint) {
            $errors['identity_endpoint'] = ['A project with this identity endpoint already exists for this environment.'];
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listChecks(User $user, string|int $projectKey): array
    {
        $project = $this->findProject($user, $projectKey);

        $apiChecks = $project->packageManagedApis()
            ->with(['assertions', 'latestResult', 'latestDiagnosticResult'])
            ->orderBy('package_name')
            ->get()
            ->map(function (MonitorApis $check) use ($project): array {
                $check->setRelation('project', $project);

                return $this->checkPayload($check);
            });

        $websiteChecks = $project->packageManagedWebsites()
            ->with(['latestLogHistory', 'latestDiagnosticLogHistory'])
            ->orderBy('package_name')
            ->get()
            ->map(function (Website $website) use ($project): array {
                $website->setRelation('project', $project);

                return $this->websiteCheckPayload($website);
            });

        $componentChecks = $project->components()
            ->with([
                'activeMonitorApis:id,project_component_id,current_status',
                'activeWebsites:id,project_component_id,current_status',
            ])
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

        if (($data['type'] ?? 'api') === 'website') {
            return $this->upsertWebsiteCheck($project, $data);
        }

        return DB::transaction(function () use ($project, $data): array {
            $payload = $data;
            $checkKey = $payload['key'];
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

            $schedule = $this->effectiveSchedule($payload['schedule'] ?? null, $check);
            $normalizedSchedule = IntervalParser::normalizeOrFail($schedule, 'schedule');

            $checkData = [
                'project_id' => $project->id,
                'created_by' => $project->created_by,
                'title' => $payload['name'],
                'url' => $this->resolveUrl($project->base_url, $payload['url']),
                'http_method' => strtoupper($payload['method'] ?? 'GET'),
                'request_path' => $payload['url'],
                'data_path' => $this->primaryDataPath($payload['assertions'] ?? []),
                'headers' => $payload['headers'] ?? [],
                'request_body_type' => $payload['request_body_type'] ?? null,
                'request_body' => $payload['request_body'] ?? null,
                'expected_status' => $payload['expected_status'] ?? 200,
                'timeout_seconds' => $payload['timeout_seconds'] ?? null,
                'max_response_time_ms' => $payload['max_response_time_ms'] ?? null,
                'package_schedule' => $schedule,
                // Stale detection reads package_interval, so persist the compact normalized form there.
                'package_interval' => $normalizedSchedule,
                'is_enabled' => $payload['enabled'] ?? true,
                'source' => 'package',
                'package_name' => $checkKey,
                'last_synced_at' => now(),
            ];

            $assertionsChanged = ! $created
                && $this->apiAssertionsChanged($check, $payload['assertions'] ?? []);

            if ($this->apiTargetChanged($check, $checkData, $assertionsChanged)) {
                $checkData += $this->awaitingLiveHealthAttributes();
            }

            $check->fill($checkData);
            $check->save();

            if ($created || $assertionsChanged) {
                $this->syncAssertions($check, $payload['assertions'] ?? []);
            }

            $check->load(['assertions', 'latestResult']);
            $check->setRelation('project', $project);

            return [
                'created' => $created,
                'check' => $this->checkPayload($check),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function upsertWebsiteCheck(Project $project, array $data): array
    {
        return DB::transaction(function () use ($project, $data): array {
            $checkKey = $data['key'];
            $website = Website::withTrashed()
                ->where('project_id', $project->id)
                ->where('source', 'package')
                ->where('package_name', $checkKey)
                ->lockForUpdate()
                ->first();

            $created = false;
            $wasRestored = false;

            if (! $website instanceof Website) {
                $website = new Website;
                $created = true;
            } elseif ($website->trashed()) {
                $website->restore();
                $wasRestored = true;
            }

            $enabled = $data['enabled'] ?? true;
            $checkTypes = $this->effectiveWebsiteCheckTypes($data['check_types'] ?? null, $website);
            $schedule = $this->effectiveWebsiteSchedule($data['schedule'] ?? null, $website);
            $normalizedSchedule = IntervalParser::normalizeOrFail($schedule, 'schedule');
            $uptimeEnabled = $enabled && in_array('uptime', $checkTypes, true);
            $sslEnabled = $enabled && in_array('ssl', $checkTypes, true);
            $this->validateWebsiteSchedule($normalizedSchedule, $uptimeEnabled, $sslEnabled);
            $resolvedUrl = $this->resolveUrl($project->base_url, $data['url']);

            $payload = [
                'project_id' => $project->id,
                'created_by' => $project->created_by,
                'name' => $data['name'],
                'url' => $resolvedUrl,
                'description' => '',
                'uptime_check' => $uptimeEnabled,
                'uptime_interval' => $uptimeEnabled ? IntervalParser::toMinutes($normalizedSchedule) : null,
                'ssl_check' => $sslEnabled,
                'source' => 'package',
                'package_name' => $checkKey,
                'package_interval' => $enabled ? $normalizedSchedule : null,
                'last_synced_at' => now(),
            ];

            if (! $enabled) {
                $payload += Website::disabledLiveHealthAttributes('Disabled by Checkybot control API.');
            } elseif ($this->websiteTargetChangedForUpsert($website, $resolvedUrl)) {
                $payload += $this->awaitingWebsiteLiveHealthAttributes();
            } elseif ($created || $wasRestored || ! $this->websiteHasEnabledStatusCheck($website)) {
                $payload += $this->awaitingWebsiteLiveHealthAttributes();
            }

            $website->fill($payload);
            $website->save();
            $website->load(['latestLogHistory', 'latestDiagnosticLogHistory']);
            $website->setRelation('project', $project);

            return [
                'created' => $created,
                'check' => $this->websiteCheckPayload($website),
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

            return $this->componentCheckPayload($check->fresh([
                'activeMonitorApis:id,project_component_id,current_status',
                'activeWebsites:id,project_component_id,current_status',
            ]));
        }

        if ($check instanceof Website) {
            $check->forceFill([
                'uptime_check' => false,
                'ssl_check' => false,
                'current_status' => 'unknown',
                'status_summary' => 'Disabled by Checkybot control API.',
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
            ->with('latestDiagnosticResult')
            ->where('is_enabled', true)
            ->orderBy('package_name')
            ->get();
        $websiteChecks = $project->packageManagedWebsites()
            ->with('latestDiagnosticLogHistory')
            ->where(function (Builder $query): void {
                $query->where('uptime_check', true)
                    ->orWhere('ssl_check', true);
            })
            ->orderBy('package_name')
            ->get();

        [$queuedApiChecks, $skippedApiChecks] = $apiChecks->partition(
            fn (MonitorApis $check): bool => ! $check->hasQueuedDiagnostic(),
        );
        [$queuedWebsiteChecks, $skippedWebsiteChecks] = $websiteChecks->partition(
            fn (Website $website): bool => ! $website->hasQueuedDiagnostic(),
        );

        $checksSkippedAlreadyQueued = $skippedApiChecks->count() + $skippedWebsiteChecks->count();
        $jobs = $queuedApiChecks
            ->map(fn (MonitorApis $check): RunApiMonitorDiagnosticJob => new RunApiMonitorDiagnosticJob($check->withoutRelations()))
            ->merge($queuedWebsiteChecks->map(fn (Website $website): LogUptimeSslJob => new LogUptimeSslJob($website->withoutRelations(), onDemand: true)));

        if ($jobs->isEmpty()) {
            return [
                'project' => $this->projectIdentity($project),
                'status' => $checksSkippedAlreadyQueued > 0 ? 'already_queued' : 'no_enabled_checks',
                'triggered_at' => now()->toISOString(),
                'checks_queued' => 0,
                'checks_skipped_already_queued' => $checksSkippedAlreadyQueued,
                'run_batch' => null,
            ];
        }

        $queuedAt = now();

        MonitorApis::query()
            ->whereKey($queuedApiChecks->modelKeys())
            ->update(['diagnostic_queued_at' => $queuedAt]);

        Website::query()
            ->whereKey($queuedWebsiteChecks->modelKeys())
            ->update(['diagnostic_queued_at' => $queuedAt]);

        $batch = Bus::batch($jobs)
            ->name($this->controlProjectRunBatchName($project))
            ->withOption('checkybot_control', [
                'project_id' => $project->id,
                'user_id' => $project->created_by,
            ])
            ->allowFailures()
            ->dispatch();

        $project->forceFill([
            'latest_diagnostic_run_batch_id' => $batch->id,
            'latest_diagnostic_run_batch_queued_at' => $queuedAt,
        ])->save();

        return [
            'project' => $this->projectIdentity($project),
            'status' => 'queued',
            'triggered_at' => now()->toISOString(),
            'checks_queued' => $jobs->count(),
            'checks_skipped_already_queued' => $checksSkippedAlreadyQueued,
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
        $monitorIds = MonitorApis::query()
            ->where('created_by', $user->id)
            ->when($project instanceof Project, fn (Builder $query): Builder => $query->where('project_id', $project->id))
            ->pluck('id');

        if ($monitorIds->isEmpty()) {
            return [];
        }

        return MonitorApiResult::query()
            ->with('monitorApi.project')
            ->whereIn('monitor_api_id', $monitorIds)
            ->orderByDesc('created_at')
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
        $websiteIds = Website::query()
            ->where('created_by', $user->id)
            ->when($project instanceof Project, fn (Builder $query): Builder => $query->where('project_id', $project->id))
            ->pluck('id');

        if ($websiteIds->isEmpty()) {
            return [];
        }

        return WebsiteLogHistory::query()
            ->with('website.project')
            ->whereIn('website_id', $websiteIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (WebsiteLogHistory $result): array => $this->websiteResultPayload($result))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentComponentRuns(User $user, ?Project $project, int $limit): array
    {
        return [];
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
        ])
            ->sortByDesc(fn (array $failure): string => (string) ($failure['checked_at'] ?? $failure['created_at'] ?? ''))
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * @param  array<int, string>  $statuses
     * @param  array<int, string>  $exclude
     * @return array<int, array<string, mixed>>
     */
    public function currentIssues(
        User $user,
        ?string $projectKey = null,
        ?string $type = null,
        array $statuses = ['warning', 'danger'],
        int $limit = 25,
        array $exclude = [],
        ?string $cause = null,
        ?int $minStreak = null,
        ?string $firstFailedBefore = null,
    ): array {
        $limit = min(max($limit, 1), 100);
        $statuses = array_values(array_intersect($statuses, ['warning', 'danger', 'pending', 'unknown']));
        $cause = in_array($cause, self::CURRENT_ISSUE_CAUSES, true) ? $cause : null;
        $minStreak = $minStreak !== null ? min(max($minStreak, 1), 1000) : null;
        $firstFailedBefore = filled($firstFailedBefore) ? Carbon::parse($firstFailedBefore) : null;
        $queryLimit = $cause === null && $minStreak === null && $firstFailedBefore === null ? $limit : null;

        if ($statuses === []) {
            $statuses = ['warning', 'danger'];
        }

        $project = $projectKey !== null ? $this->findProject($user, $projectKey) : null;
        $type = $type === 'all' ? null : $type;

        $issues = collect();

        if ($type === null || $type === 'project') {
            $issues = $issues->merge($this->currentProjectIssues($user, $project, $statuses, $queryLimit));
        }

        if ($type === null || $type === 'api') {
            $issues = $issues->merge($this->currentApiIssues($user, $project, $statuses, $queryLimit));
        }

        if ($type === null || $type === 'website') {
            $issues = $issues->merge($this->currentWebsiteIssues($user, $project, $statuses, $queryLimit));
        }

        if ($type === null || $type === 'component') {
            $issues = $issues->merge($this->currentComponentIssues($user, $project, $statuses, $queryLimit));
        }

        return $issues
            ->reject(fn (array $issue): bool => $this->matchesCurrentIssueExclude($issue, $exclude))
            ->when($cause !== null, fn ($issues) => $issues->where('cause', $cause))
            ->filter(fn (array $issue): bool => $this->matchesCurrentIssueStreakFilters($issue, $minStreak, $firstFailedBefore))
            ->sort(function (array $a, array $b): int {
                $statusComparison = $this->statusSortRank($a['status'] ?? null) <=> $this->statusSortRank($b['status'] ?? null);

                if ($statusComparison !== 0) {
                    return $statusComparison;
                }

                return strcmp(
                    (string) ($b['last_checked_at'] ?? $b['updated_at'] ?? ''),
                    (string) ($a['last_checked_at'] ?? $a['updated_at'] ?? ''),
                );
            })
            ->values()
            ->take($limit)
            ->all();
    }

    private function matchesCurrentIssueStreakFilters(array $issue, ?int $minStreak, ?Carbon $firstFailedBefore): bool
    {
        if ($minStreak === null && $firstFailedBefore === null) {
            return true;
        }

        $streak = $issue['scheduled_failure_streak'] ?? null;

        if (! is_array($streak)) {
            return false;
        }

        if ($minStreak !== null && (int) ($streak['count'] ?? 0) < $minStreak) {
            return false;
        }

        if ($firstFailedBefore === null) {
            return true;
        }

        if (blank($streak['first_failed_at'] ?? null)) {
            return false;
        }

        return Carbon::parse($streak['first_failed_at'])->lt($firstFailedBefore);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listNotificationChannels(User $user): array
    {
        return NotificationChannels::query()
            ->where('created_by', $user->id)
            ->latest('updated_at')
            ->get()
            ->map(fn (NotificationChannels $channel): array => $this->notificationChannelPayload($channel))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{created: bool, channel: array<string, mixed>}
     */
    public function upsertNotificationChannel(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $channel = isset($data['id'])
                ? NotificationChannels::query()
                    ->where('created_by', $user->id)
                    ->whereKey($data['id'])
                    ->lockForUpdate()
                    ->firstOrFail()
                : new NotificationChannels(['created_by' => $user->id]);

            $created = ! $channel->exists;

            $channel->fill([
                'title' => $data['title'],
                'method' => $data['method'],
                'url' => $data['url'],
                'description' => $data['description'] ?? null,
                'request_body' => $data['request_body'] ?? [],
            ]);
            $channel->save();

            return [
                'created' => $created,
                'channel' => $this->notificationChannelPayload($channel),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteNotificationChannel(User $user, int $channelId): array
    {
        $channel = NotificationChannels::query()
            ->where('created_by', $user->id)
            ->whereKey($channelId)
            ->firstOrFail();

        $settingsCount = NotificationSetting::query()
            ->where('user_id', $user->id)
            ->where('notification_channel_id', $channel->id)
            ->count();

        if ($settingsCount > 0) {
            abort(409, 'Notification channel is still used by notification settings. Delete or move those settings first.');
        }

        $payload = $this->notificationChannelPayload($channel);
        $channel->delete();

        return [
            'deleted' => true,
            'channel' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testNotificationChannel(User $user, int $channelId): array
    {
        $channel = NotificationChannels::query()
            ->where('created_by', $user->id)
            ->whereKey($channelId)
            ->firstOrFail();

        $response = $channel->sendWebhookNotification([
            'message' => 'Checkybot webhook channel test',
            'description' => 'This test confirms the saved webhook channel can receive Checkybot notifications.',
        ], 'test');

        return [
            'ok' => (int) ($response['code'] ?? 0) >= 200 && (int) ($response['code'] ?? 0) < 300,
            'summary' => NotificationChannels::summarizeDeliveryResponse($response),
            'channel' => $this->notificationChannelPayload($channel->fresh()),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listNotificationSettings(User $user): array
    {
        return NotificationSetting::query()
            ->with('channel')
            ->where('user_id', $user->id)
            ->where('scope', NotificationScopesEnum::GLOBAL->value)
            ->latest('updated_at')
            ->get()
            ->map(fn (NotificationSetting $setting): array => $this->notificationSettingPayload($setting))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{created: bool, setting: array<string, mixed>}
     */
    public function upsertNotificationSetting(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $setting = isset($data['id'])
                ? NotificationSetting::query()
                    ->where('user_id', $user->id)
                    ->where('scope', NotificationScopesEnum::GLOBAL->value)
                    ->whereKey($data['id'])
                    ->lockForUpdate()
                    ->firstOrFail()
                : new NotificationSetting(['user_id' => $user->id]);

            $created = ! $setting->exists;
            $channelType = NotificationChannelTypesEnum::from($data['channel_type']);

            if ($channelType === NotificationChannelTypesEnum::WEBHOOK) {
                NotificationChannels::query()
                    ->where('created_by', $user->id)
                    ->whereKey($data['notification_channel_id'])
                    ->firstOrFail();
            }

            $setting->fill([
                'scope' => NotificationScopesEnum::GLOBAL->value,
                'inspection' => $data['inspection'],
                'channel_type' => $channelType->value,
                'notification_channel_id' => $channelType === NotificationChannelTypesEnum::WEBHOOK
                    ? $data['notification_channel_id']
                    : null,
                'address' => $channelType === NotificationChannelTypesEnum::MAIL
                    ? $data['address']
                    : null,
                'flag_active' => $data['active'] ?? true,
                'website_id' => null,
                'monitor_api_id' => null,
                'project_component_id' => null,
            ]);
            $setting->save();

            return [
                'created' => $created,
                'setting' => $this->notificationSettingPayload($setting->fresh('channel')),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteNotificationSetting(User $user, int $settingId): array
    {
        $setting = NotificationSetting::query()
            ->with('channel')
            ->where('user_id', $user->id)
            ->where('scope', NotificationScopesEnum::GLOBAL->value)
            ->whereKey($settingId)
            ->firstOrFail();

        $payload = $this->notificationSettingPayload($setting);
        $setting->delete();

        return [
            'deleted' => true,
            'setting' => $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testNotificationSetting(User $user, int $settingId): array
    {
        $setting = NotificationSetting::query()
            ->with('channel')
            ->where('user_id', $user->id)
            ->where('scope', NotificationScopesEnum::GLOBAL->value)
            ->whereKey($settingId)
            ->firstOrFail();

        $result = $setting->sendTestNotification();

        return [
            'ok' => $result['ok'],
            'title' => $result['title'],
            'body' => $result['body'],
            'setting' => $this->notificationSettingPayload($setting->fresh('channel')),
        ];
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<int, array<string, mixed>>
     */
    private function currentProjectIssues(User $user, ?Project $project, array $statuses, ?int $limit): array
    {
        $query = $this->projectQuery($user);

        if ($project instanceof Project) {
            $query->whereKey($project->id);
        }

        return $query
            ->latest('updated_at')
            ->get()
            ->filter(fn (Project $project): bool => in_array($this->projectSetupIssueStatus($project), $statuses, true))
            ->when($limit !== null, fn ($projects) => $projects->take($limit))
            ->map(fn (Project $project): array => $this->projectSetupIssuePayload($project))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<int, array<string, mixed>>
     */
    private function currentApiIssues(User $user, ?Project $project, array $statuses, ?int $limit): array
    {
        $query = MonitorApis::query()
            ->with(['project', 'assertions', 'latestResult', 'latestScheduledResult', 'latestDiagnosticResult'])
            ->where('created_by', $user->id)
            ->where('is_enabled', true)
            ->whereIn('current_status', $statuses);

        if ($project instanceof Project) {
            $query->where('project_id', $project->id);
        }

        return $query
            ->orderByRaw("CASE current_status WHEN 'danger' THEN 0 WHEN 'warning' THEN 1 WHEN 'pending' THEN 2 WHEN 'unknown' THEN 3 ELSE 4 END")
            ->latest('updated_at')
            ->when($limit !== null, fn (Builder $query): Builder => $query->limit($limit))
            ->get()
            ->map(function (MonitorApis $check): array {
                $scheduledResult = $check->relationLoaded('latestScheduledResult')
                    ? $check->latestScheduledResult
                    : $check->latestScheduledResult()->first();
                $diagnosticResult = $check->relationLoaded('latestDiagnosticResult')
                    ? $check->latestDiagnosticResult
                    : $check->latestDiagnosticResult()->first();

                return $this->currentIssuePayload(
                    $check->project,
                    $this->checkPayload($check),
                    $this->currentApiIssueCause($check),
                    $this->apiManualScheduledDriftPayload($check, $scheduledResult, $diagnosticResult),
                );
            })
            ->all();
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<int, array<string, mixed>>
     */
    private function currentWebsiteIssues(User $user, ?Project $project, array $statuses, ?int $limit): array
    {
        $query = Website::query()
            ->with(['project', 'latestLogHistory', 'latestScheduledLogHistory', 'latestDiagnosticLogHistory'])
            ->where('created_by', $user->id)
            ->where(fn (Builder $query) => $this->activeWebsiteCheckConstraint($query))
            ->whereIn('current_status', $statuses);

        if ($project instanceof Project) {
            $query->where('project_id', $project->id);
        }

        return $query
            ->orderByRaw("CASE current_status WHEN 'danger' THEN 0 WHEN 'warning' THEN 1 WHEN 'pending' THEN 2 WHEN 'unknown' THEN 3 ELSE 4 END")
            ->latest('updated_at')
            ->when($limit !== null, fn (Builder $query): Builder => $query->limit($limit))
            ->get()
            ->map(function (Website $website): array {
                $scheduledResult = $website->relationLoaded('latestScheduledLogHistory')
                    ? $website->latestScheduledLogHistory
                    : $website->latestScheduledLogHistory()->first();
                $diagnosticResult = $website->relationLoaded('latestDiagnosticLogHistory')
                    ? $website->latestDiagnosticLogHistory
                    : $website->latestDiagnosticLogHistory()->first();

                return $this->currentIssuePayload(
                    $website->project,
                    $this->websiteCheckPayload($website),
                    $this->currentWebsiteIssueCause($website),
                    $this->websiteManualScheduledDriftPayload($website, $scheduledResult, $diagnosticResult),
                );
            })
            ->all();
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<int, array<string, mixed>>
     */
    private function currentComponentIssues(User $user, ?Project $project, array $statuses, ?int $limit): array
    {
        $query = ProjectComponent::query()
            ->with(['project', 'activeMonitorApis', 'activeWebsites'])
            ->where('created_by', $user->id)
            ->where('is_archived', false);

        if ($project instanceof Project) {
            $query->where('project_id', $project->id);
        }

        return $query
            ->latest('updated_at')
            ->get()
            ->filter(fn (ProjectComponent $component): bool => in_array($this->componentStatusBucket($component), $statuses, true))
            ->when($limit !== null, fn ($components) => $components->take($limit))
            ->map(fn (ProjectComponent $component): array => $this->currentIssuePayload(
                $component->project,
                $this->componentCheckPayload($component),
                $this->currentComponentIssueCause($component),
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array<string, mixed>
     */
    private function currentIssuePayload(?Project $project, array $check, ?string $cause, ?array $manualScheduledDrift = null): array
    {
        $payload = [
            'project' => $project instanceof Project ? $this->projectIdentity($project) + [
                'name' => $project->name,
                'environment' => $project->environment,
            ] : null,
            'check' => $check,
            'status' => $check['status'] ?? 'unknown',
            'summary' => $check['status_summary'] ?? null,
            'cause' => $cause,
            'last_checked_at' => $check['last_checked_at'] ?? null,
            'updated_at' => $check['updated_at'] ?? null,
        ];

        $action = $this->currentIssueAction($check['type'] ?? null, $cause);

        if ($action !== null) {
            $payload['action'] = $action;
        }

        if ($manualScheduledDrift !== null) {
            $payload['manual_scheduled_drift'] = $manualScheduledDrift;
        }

        if (in_array($check['type'] ?? null, ['api', 'website'], true)) {
            $payload['scheduled_failure_streak'] = $check['scheduled_failure_streak'] ?? [
                'count' => 0,
                'first_failed_at' => null,
            ];
        }

        return $payload;
    }

    private function currentIssueAction(?string $type, ?string $cause): ?string
    {
        if (! in_array($type, ['api', 'website'], true)) {
            return null;
        }

        return match ($cause) {
            'dns' => 'Check DNS records and nameserver propagation for this hostname, then rerun the check.',
            'timeout' => 'Confirm the endpoint responds from outside the app network, then raise the timeout or investigate slow upstream work.',
            'http_4xx' => $type === 'api'
                ? 'Verify the URL, route, and required auth or headers; Checkybot is receiving a client error.'
                : 'Verify the page URL, redirects, and access rules; Checkybot is receiving a client error.',
            'http_5xx' => 'Inspect the app/server logs for this endpoint and rerun after fixing the server error.',
            'assertion' => 'Compare the latest response body with the saved assertions and update the API or assertion rule.',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function apiManualScheduledDriftPayload(MonitorApis $check, ?MonitorApiResult $scheduledResult, ?MonitorApiResult $diagnosticResult): array
    {
        return $this->manualScheduledDriftPayload(
            $this->apiResultDriftPoint($scheduledResult),
            $this->apiResultDriftPoint($diagnosticResult),
            $this->freshnessWindowSeconds($check->package_interval ?? $check->package_schedule ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteManualScheduledDriftPayload(Website $website, ?WebsiteLogHistory $scheduledResult, ?WebsiteLogHistory $diagnosticResult): array
    {
        return $this->manualScheduledDriftPayload(
            $this->websiteResultDriftPoint($scheduledResult),
            $this->websiteResultDriftPoint($diagnosticResult),
            $this->freshnessWindowSeconds($website->package_interval ?? null, $website->uptime_interval),
        );
    }

    /**
     * @param  array<string, mixed>|null  $scheduled
     * @param  array<string, mixed>|null  $manual
     * @return array<string, mixed>
     */
    private function manualScheduledDriftPayload(?array $scheduled, ?array $manual, int $freshnessWindowSeconds): array
    {
        $manual = $this->markManualDriftFreshness($manual, $scheduled, $freshnessWindowSeconds);
        $detected = $scheduled !== null
            && $manual !== null
            && $scheduled['status'] !== $manual['status'];

        return [
            'detected' => $detected,
            'scheduled' => $scheduled,
            'manual' => $manual,
            'summary' => $this->manualScheduledDriftSummary($scheduled, $manual, $detected),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $manual
     * @param  array<string, mixed>|null  $scheduled
     * @return array<string, mixed>|null
     */
    private function markManualDriftFreshness(?array $manual, ?array $scheduled, int $freshnessWindowSeconds): ?array
    {
        if ($manual === null) {
            return null;
        }

        $manualCheckedAt = filled($manual['checked_at'] ?? null) ? Carbon::parse((string) $manual['checked_at']) : null;
        $scheduledCheckedAt = filled($scheduled['checked_at'] ?? null) ? Carbon::parse((string) $scheduled['checked_at']) : null;

        if ($manualCheckedAt === null) {
            return $manual + [
                'age_seconds' => null,
                'age_label' => null,
                'stale' => false,
            ];
        }

        $ageSeconds = max(0, $manualCheckedAt->diffInSeconds(now()));
        $olderThanScheduled = $scheduledCheckedAt !== null && $manualCheckedAt->lt($scheduledCheckedAt);

        return $manual + [
            'age_seconds' => $ageSeconds,
            'age_label' => $this->durationLabel($ageSeconds),
            'stale' => $olderThanScheduled || $ageSeconds > $freshnessWindowSeconds,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $scheduled
     * @param  array<string, mixed>|null  $manual
     */
    private function manualScheduledDriftSummary(?array $scheduled, ?array $manual, bool $detected): ?string
    {
        if ($manual === null || $scheduled === null) {
            return null;
        }

        if (($manual['stale'] ?? false) === true) {
            $manualCheckedAt = filled($manual['checked_at'] ?? null) ? Carbon::parse((string) $manual['checked_at']) : null;
            $scheduledCheckedAt = filled($scheduled['checked_at'] ?? null) ? Carbon::parse((string) $scheduled['checked_at']) : null;

            if ($manualCheckedAt !== null && $scheduledCheckedAt !== null && $manualCheckedAt->lt($scheduledCheckedAt)) {
                $difference = $this->durationLabel($manualCheckedAt->diffInSeconds($scheduledCheckedAt));

                return "Latest manual diagnostic is {$manual['status']} but is {$difference} older than the scheduled {$scheduled['status']}; prefer the scheduled status until a new diagnostic is queued.";
            }

            if (filled($manual['age_label'] ?? null)) {
                return "Latest manual diagnostic is {$manual['status']} but is {$manual['age_label']} old; prefer the scheduled status until a new diagnostic is queued.";
            }
        }

        return $detected
            ? "Latest manual diagnostic status ({$manual['status']}) differs from latest scheduled status ({$scheduled['status']}). Prefer the current issue status until a scheduled run recovers."
            : null;
    }

    private function freshnessWindowSeconds(?string $interval, ?int $fallbackMinutes = null): int
    {
        if (is_string($interval) && filled($interval) && IntervalParser::isValid($interval)) {
            return max(IntervalParser::toMinutes($interval) * 60, 60 * 60);
        }

        if ($fallbackMinutes !== null && $fallbackMinutes > 0) {
            return max($fallbackMinutes * 60, 60 * 60);
        }

        return 60 * 60;
    }

    private function durationLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : "{$seconds} seconds";
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return $hours === 1 ? '1 hour' : "{$hours} hours";
        }

        $days = intdiv($hours, 24);

        return $days === 1 ? '1 day' : "{$days} days";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function apiResultDriftPoint(?MonitorApiResult $result): ?array
    {
        if (! $result instanceof MonitorApiResult) {
            return null;
        }

        return [
            'id' => $result->id,
            'status' => $result->status ?? ($result->is_success ? 'healthy' : 'danger'),
            'success' => (bool) $result->is_success,
            'summary' => $result->summary,
            'checked_at' => $result->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function websiteResultDriftPoint(?WebsiteLogHistory $result): ?array
    {
        if (! $result instanceof WebsiteLogHistory) {
            return null;
        }

        $status = $result->status ?? (((int) ($result->http_status_code ?? 200)) < 400 ? 'healthy' : 'danger');

        return [
            'id' => $result->id,
            'status' => $status,
            'success' => in_array($status, ['healthy', null], true)
                && ((int) ($result->http_status_code ?? 200)) < 400,
            'summary' => $result->summary,
            'checked_at' => $result->created_at?->toISOString(),
        ];
    }

    private function currentApiIssueCause(MonitorApis $check): ?string
    {
        $result = $check->relationLoaded('latestResult') ? $check->latestResult : $check->latestResult()->first();

        if ($this->isStaleSetupIssue($check->getRawOriginal('stale_at'), $check->status_summary)) {
            return 'stale_setup';
        }

        if ($result instanceof MonitorApiResult) {
            if (in_array($result->transport_error_type, ['dns', 'timeout'], true)) {
                return $result->transport_error_type;
            }

            $httpCause = $this->httpCause($result->http_code);

            if ($httpCause !== null) {
                return $httpCause;
            }

            return $this->hasFailedAssertions($result->failed_assertions)
                ? 'assertion'
                : $this->summaryCause($result->summary);
        }

        return $this->summaryCause($check->status_summary);
    }

    private function currentWebsiteIssueCause(Website $website): ?string
    {
        $result = $website->relationLoaded('latestLogHistory') ? $website->latestLogHistory : $website->latestLogHistory()->first();

        if ($this->isStaleSetupIssue($website->getRawOriginal('stale_at'), $website->status_summary)) {
            return 'stale_setup';
        }

        if ($result instanceof WebsiteLogHistory) {
            if (in_array($result->transport_error_type, ['dns', 'timeout'], true)) {
                return $result->transport_error_type;
            }

            return $this->httpCause($result->http_status_code) ?? $this->summaryCause($result->summary);
        }

        return $this->summaryCause($website->status_summary);
    }

    private function currentComponentIssueCause(ProjectComponent $component): ?string
    {
        return $this->componentStatusBucket($component) === 'pending'
            ? 'stale_setup'
            : $this->summaryCause($component->derivedStatusSummary());
    }

    private function httpCause(?int $code): ?string
    {
        return match (true) {
            $code !== null && $code >= 400 && $code <= 499 => 'http_4xx',
            $code !== null && $code >= 500 && $code <= 599 => 'http_5xx',
            default => null,
        };
    }

    private function hasFailedAssertions(mixed $failedAssertions): bool
    {
        return is_array($failedAssertions) && $failedAssertions !== [];
    }

    private function isStaleSetupIssue(mixed $staleAt, ?string $summary): bool
    {
        return filled($staleAt) || $this->summaryCause($summary) === 'stale_setup';
    }

    private function summaryCause(?string $summary): ?string
    {
        $summary = Str::lower((string) $summary);

        if ($summary === '') {
            return null;
        }

        if (str_contains($summary, 'could not resolve') || str_contains($summary, 'dns')) {
            return 'dns';
        }

        if (str_contains($summary, 'timed out') || str_contains($summary, 'timeout')) {
            return 'timeout';
        }

        if (str_contains($summary, 'assertion')) {
            return 'assertion';
        }

        if (str_contains($summary, 'no heartbeat') || str_contains($summary, 'no scheduled api check') || str_contains($summary, 'awaiting first active child')) {
            return 'stale_setup';
        }

        if (preg_match('/http\s+([45]\d{2})/', $summary, $matches) === 1) {
            return $this->httpCause((int) $matches[1]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function projectSetupIssuePayload(Project $project): array
    {
        $setupVerification = $this->setupVerificationPayload($project);

        return [
            'project' => $this->projectIdentity($project) + [
                'name' => $project->name,
                'environment' => $project->environment,
            ],
            'check' => [
                'type' => 'project',
                'key' => $project->package_key ?? (string) $project->id,
                'name' => "{$project->name} setup",
                'supports_run' => false,
                'setup_verification' => $setupVerification,
                'created_at' => $project->created_at?->toISOString(),
                'updated_at' => $project->updated_at?->toISOString(),
            ],
            'status' => $this->projectSetupIssueStatus($project),
            'summary' => $project->setupVerificationSummary(),
            'cause' => 'stale_setup',
            'action' => $project->setupVerificationAction(),
            'last_checked_at' => $project->last_synced_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
        ];
    }

    private function projectSetupIssueStatus(Project $project): string
    {
        return match ($project->setupVerificationState()) {
            'synced' => 'healthy',
            default => 'warning',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationChannelPayload(NotificationChannels $channel): array
    {
        return [
            'id' => $channel->id,
            'title' => $channel->title,
            'method' => $channel->method,
            'url' => $channel->maskedWebhookUrlForDisplay(),
            'description' => $channel->description,
            'request_body' => $channel->maskedRequestBodyForDisplay(),
            'last_delivery' => [
                'kind' => $channel->last_delivery_kind,
                'succeeded' => $channel->last_delivery_succeeded,
                'response_code' => $channel->last_delivery_response_code,
                'summary' => $channel->last_delivery_summary,
                'attempted_at' => $channel->last_delivery_attempted_at?->toISOString(),
            ],
            'created_at' => $channel->created_at?->toISOString(),
            'updated_at' => $channel->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationSettingPayload(NotificationSetting $setting): array
    {
        $channelType = $setting->channel_type instanceof NotificationChannelTypesEnum
            ? $setting->channel_type
            : NotificationChannelTypesEnum::tryFrom((string) $setting->channel_type);
        $scope = $setting->scope instanceof NotificationScopesEnum
            ? $setting->scope
            : NotificationScopesEnum::tryFrom((string) $setting->scope);

        return [
            'id' => $setting->id,
            'scope' => $scope?->value ?? (string) $setting->scope,
            'inspection' => $setting->inspection instanceof \BackedEnum
                ? $setting->inspection->value
                : (string) $setting->inspection,
            'channel_type' => $channelType?->value ?? (string) $setting->channel_type,
            'channel' => $setting->channel instanceof NotificationChannels
                ? [
                    'id' => $setting->channel->id,
                    'title' => $setting->channel->title,
                    'url' => $setting->channel->maskedWebhookUrlForDisplay(),
                ]
                : null,
            'address' => $channelType === NotificationChannelTypesEnum::MAIL ? $setting->address : null,
            'active' => (bool) $setting->flag_active,
            'last_delivery' => [
                'kind' => $setting->last_delivery_kind,
                'succeeded' => $setting->last_delivery_succeeded,
                'response_code' => $setting->last_delivery_response_code,
                'summary' => $setting->last_delivery_summary,
                'attempted_at' => $setting->last_delivery_attempted_at?->toISOString(),
            ],
            'created_at' => $setting->created_at?->toISOString(),
            'updated_at' => $setting->updated_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $issue
     * @param  array<int, string>  $exclude
     */
    private function matchesCurrentIssueExclude(array $issue, array $exclude): bool
    {
        $exclude = collect($exclude)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => Str::lower(trim($value)))
            ->values();

        if ($exclude->isEmpty()) {
            return false;
        }

        $check = $issue['check'] ?? [];
        $haystack = Str::lower(implode(' ', array_filter([
            $check['key'] ?? null,
            $check['name'] ?? null,
            $check['url'] ?? null,
            $issue['summary'] ?? null,
        ], fn (mixed $value): bool => is_scalar($value))));

        return $exclude->contains(fn (string $needle): bool => str_contains($haystack, $needle));
    }

    private function statusSortRank(?string $status): int
    {
        return match ($status) {
            'danger' => 0,
            'warning' => 1,
            'pending' => 2,
            'unknown' => 3,
            default => 4,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestApiFailures(User $user, ?Project $project, int $limit): array
    {
        return MonitorApis::query()
            ->with(['project', 'latestScheduledResult'])
            ->where('created_by', $user->id)
            ->where('is_enabled', true)
            ->whereIn('current_status', ['warning', 'danger'])
            ->when($project instanceof Project, fn (Builder $query): Builder => $query->where('project_id', $project->id))
            ->get()
            ->map(function (MonitorApis $monitor): ?MonitorApiResult {
                $result = $monitor->latestScheduledResult;

                if ($result instanceof MonitorApiResult) {
                    $result->setRelation('monitorApi', $monitor);
                }

                return $result;
            })
            ->filter(fn (?MonitorApiResult $result): bool => $result instanceof MonitorApiResult
                && ((bool) $result->is_success === false || in_array($result->status, ['warning', 'danger'], true)))
            ->sortByDesc(fn (MonitorApiResult $result): string => (string) $result->created_at)
            ->take($limit)
            ->map(fn (MonitorApiResult $result): array => $this->resultPayload($result))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function latestWebsiteFailures(User $user, ?Project $project, int $limit): array
    {
        return Website::query()
            ->with(['project', 'latestScheduledLogHistory'])
            ->where('created_by', $user->id)
            ->whereIn('current_status', ['warning', 'danger'])
            ->where(function (Builder $query): void {
                $query
                    ->where('uptime_check', true)
                    ->orWhere('ssl_check', true);
            })
            ->when($project instanceof Project, fn (Builder $query): Builder => $query->where('project_id', $project->id))
            ->get()
            ->map(function (Website $website): ?WebsiteLogHistory {
                $result = $website->latestScheduledLogHistory;

                if ($result instanceof WebsiteLogHistory) {
                    $result->setRelation('website', $website);
                }

                return $result;
            })
            ->filter(fn (?WebsiteLogHistory $result): bool => $result instanceof WebsiteLogHistory
                && (in_array($result->status, ['warning', 'danger'], true)
                    || (int) $result->http_status_code >= 400
                    || $result->transport_error_type !== null))
            ->sortByDesc(fn (WebsiteLogHistory $result): string => (string) $result->created_at)
            ->take($limit)
            ->map(fn (WebsiteLogHistory $result): array => $this->websiteResultPayload($result))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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
                ->with([
                    'activeMonitorApis:id,project_component_id,current_status',
                    'activeWebsites:id,project_component_id,current_status',
                ])
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
            ->with([
                'activeMonitorApis:id,project_component_id,current_status',
                'activeWebsites:id,project_component_id,current_status',
            ])
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
            'setup_verification' => $this->setupVerificationPayload($project),
            'created_at' => $project->created_at?->toISOString(),
            'last_synced_at' => $project->last_synced_at?->toISOString(),
            'updated_at' => $project->updated_at?->toISOString(),
        ]);
    }

    /**
     * @return array{state: string, label: string, tone: string, summary: string, action: string, steps: array<int, array{title: string, status: string, description: string}>}
     */
    private function setupVerificationPayload(Project $project): array
    {
        return [
            'state' => $project->setupVerificationState(),
            'label' => $project->setupVerificationLabel(),
            'tone' => $project->setupVerificationTone(),
            'summary' => $project->setupVerificationSummary(),
            'action' => $project->setupVerificationAction(),
            'steps' => $project->setupVerificationSteps(),
        ];
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
            ->get(['current_status'])
            ->map(fn (MonitorApis $api): string => $this->packageCheckStatusBucket($api))
            ->countBy()
            ->map(fn ($count): int => (int) $count)
            ->all();

        $websiteCounts = $project->packageManagedWebsites()
            ->where(fn (Builder $query) => $this->activeWebsiteCheckConstraint($query))
            ->get(['current_status', 'uptime_check', 'ssl_check'])
            ->map(fn (Website $website): string => $this->packageCheckStatusBucket($website))
            ->countBy()
            ->map(fn ($count): int => (int) $count)
            ->all();

        foreach ($websiteCounts as $status => $count) {
            $counts[$status] = (int) ($counts[$status] ?? 0) + $count;
        }

        $componentCounts = $project->activeComponents()
            ->with(['activeMonitorApis', 'activeWebsites'])
            ->get(['id', 'current_status', 'is_archived', 'source'])
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

    private function packageCheckStatusBucket(MonitorApis|Website $check): string
    {
        if (in_array($check->current_status, ['warning', 'danger'], true)) {
            return $check->current_status;
        }

        return $check->current_status === 'healthy'
            ? 'healthy'
            : 'unknown';
    }

    private function componentStatusBucket(ProjectComponent $component): string
    {
        return $component->derivedCurrentStatus();
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPayload(MonitorApis $check): array
    {
        $latestResult = $check->relationLoaded('latestResult') ? $check->latestResult : $check->results()->latest()->first();
        $latestDiagnosticResult = $check->relationLoaded('latestDiagnosticResult')
            ? $check->latestDiagnosticResult
            : $check->latestDiagnosticResult()->first();

        if ($latestResult instanceof MonitorApiResult) {
            $latestResult->setRelation('monitorApi', $check);
        }

        if ($latestDiagnosticResult instanceof MonitorApiResult) {
            $latestDiagnosticResult->setRelation('monitorApi', $check);
        }

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
            'max_response_time_ms' => $check->max_response_time_ms,
            'schedule' => $check->package_schedule,
            'enabled' => $check->is_enabled,
            'supports_run' => true,
            'diagnostic_queued' => $check->hasQueuedDiagnostic(),
            'diagnostic_queued_at' => $check->diagnostic_queued_at?->toISOString(),
            'status' => $check->current_status ?? 'unknown',
            'status_summary' => $check->status_summary,
            'scheduled_failure_streak' => ScheduledFailureStreak::apiPayload($check),
            'last_synced_at' => $check->last_synced_at?->toISOString(),
            'last_checked_at' => $latestResult?->created_at?->toISOString(),
            'headers' => ApiMonitorEvidenceRedactor::redactHeaders($check->headers),
            'request_body_type' => $check->request_body_type,
            'has_request_body' => $check->hasRequestBody(),
            'assertions' => $this->assertionsPayload($check->assertions),
            'latest_result' => $latestResult instanceof MonitorApiResult ? $this->resultPayload($latestResult) : null,
            'latest_diagnostic_result' => $latestDiagnosticResult instanceof MonitorApiResult ? $this->resultPayload($latestDiagnosticResult) : null,
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
        $latestDiagnosticResult = $website->relationLoaded('latestDiagnosticLogHistory')
            ? $website->latestDiagnosticLogHistory
            : $website->latestDiagnosticLogHistory()->first();

        if ($latestResult instanceof WebsiteLogHistory) {
            $latestResult->setRelation('website', $website);
        }

        if ($latestDiagnosticResult instanceof WebsiteLogHistory) {
            $latestDiagnosticResult->setRelation('website', $website);
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
            'max_response_time_ms' => null,
            'schedule' => $website->package_interval,
            'enabled' => (bool) $website->uptime_check || (bool) $website->ssl_check,
            'supports_run' => true,
            'diagnostic_queued' => $website->hasQueuedDiagnostic(),
            'diagnostic_queued_at' => $website->diagnostic_queued_at?->toISOString(),
            'status' => $website->current_status ?? 'unknown',
            'status_summary' => $website->status_summary,
            'scheduled_failure_streak' => ScheduledFailureStreak::websitePayload($website),
            'last_synced_at' => $website->last_synced_at?->toISOString(),
            'last_checked_at' => $latestResult?->created_at?->toISOString(),
            'headers' => [],
            'request_body_type' => null,
            'has_request_body' => false,
            'assertions' => [],
            'latest_result' => $latestResult instanceof WebsiteLogHistory ? $this->websiteResultPayload($latestResult) : null,
            'latest_diagnostic_result' => $latestDiagnosticResult instanceof WebsiteLogHistory ? $this->websiteResultPayload($latestDiagnosticResult) : null,
            'updated_at' => $website->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentCheckPayload(ProjectComponent $component): array
    {
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
            'max_response_time_ms' => null,
            'schedule' => $component->declared_interval,
            'declared_interval' => $component->declared_interval,
            'interval_minutes' => $component->interval_minutes,
            'enabled' => ! (bool) $component->is_archived,
            'supports_run' => false,
            'status' => $this->componentStatusBucket($component),
            'status_summary' => $component->derivedStatusSummary(),
            'delivery_state' => $deliveryState,
            'delivery_state_label' => ProjectComponentDeliveryState::label($component),
            'is_archived' => (bool) $component->is_archived,
            'last_synced_at' => null,
            'silenced_until' => $component->silenced_until?->toISOString(),
            'headers' => [],
            'request_body_type' => null,
            'has_request_body' => false,
            'assertions' => [],
            'latest_result' => null,
            'updated_at' => $component->updated_at?->toISOString(),
        ];
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
            'max_response_time_ms' => $result->max_response_time_ms,
            'effective_timeout_seconds' => $result->effective_timeout_seconds,
            'retry_count' => $result->retry_count,
            'elapsed_wall_time_ms' => $result->elapsed_wall_time_ms,
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
    private function runCheck(MonitorApis $check): array
    {
        $execution = $this->executionService->execute($check, onDemand: true);
        /** @var MonitorApiResult $result */
        $result = $execution['result'];

        $this->notificationService->notifyApiIfTransitioned(
            $check,
            $execution['previous_status'],
            $execution['status'],
            $execution['summary'],
        );

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
     * @return array<int, string>
     */
    private function effectiveWebsiteCheckTypes(mixed $checkTypes, Website $website): array
    {
        if (is_array($checkTypes) && $checkTypes !== []) {
            return collect($checkTypes)
                ->filter(fn (mixed $type): bool => in_array($type, ['uptime', 'ssl'], true))
                ->unique()
                ->values()
                ->all();
        }

        $existingTypes = $this->websiteCheckTypes($website);

        return $existingTypes === [] ? ['uptime'] : $existingTypes;
    }

    private function effectiveWebsiteSchedule(mixed $schedule, Website $website): string
    {
        if (is_string($schedule) && filled($schedule)) {
            return $schedule;
        }

        if ($schedule !== null && ! is_string($schedule)) {
            throw ValidationException::withMessages([
                'schedule' => ['The schedule format is invalid. Use format: {number}{s|m|h|d} or every_{number}_{seconds|minutes|hours|days}.'],
            ]);
        }

        if (is_string($website->package_interval) && filled($website->package_interval) && IntervalParser::isValid($website->package_interval)) {
            return $website->package_interval;
        }

        return IntervalParser::DEFAULT_API_INTERVAL;
    }

    private function validateWebsiteSchedule(string $normalizedSchedule, bool $uptimeEnabled, bool $sslEnabled): void
    {
        if (! $uptimeEnabled && ! $sslEnabled) {
            return;
        }

        if (Str::endsWith($normalizedSchedule, 's')) {
            throw ValidationException::withMessages([
                'schedule' => ['Uptime and SSL schedules cannot be specified in seconds. Supported values: 1m, 5m, 10m, 15m, 30m, 1h, 6h, 12h, 1d.'],
            ]);
        }

        if ($uptimeEnabled && ! in_array(IntervalParser::toMinutes($normalizedSchedule), LogJobCheckUptimeSsl::SUPPORTED_INTERVALS, true)) {
            throw ValidationException::withMessages([
                'schedule' => ['Unsupported uptime interval. Supported values: 1m, 5m, 10m, 15m, 30m, 1h, 6h, 12h, 1d.'],
            ]);
        }
    }

    private function websiteTargetChangedForUpsert(Website $website, string $url): bool
    {
        return $website->exists
            && ! $website->trashed()
            && $website->url !== $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function awaitingWebsiteLiveHealthAttributes(): array
    {
        return [
            'current_status' => 'unknown',
            'status_summary' => null,
            'diagnostic_queued_at' => null,
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

    /**
     * @param  array<int, array<string, mixed>>  $assertions
     */
    private function apiAssertionsChanged(MonitorApis $check, array $assertions): bool
    {
        return $this->storedAssertions($check) !== $this->incomingAssertions($assertions);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storedAssertions(MonitorApis $check): array
    {
        return $check->assertions()
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
                'sort_order' => $assertion['sort_order'] ?? ($index + 1),
                'is_active' => $assertion['active'] ?? true,
            ])
            ->sortBy([
                ['sort_order', 'asc'],
                ['data_path', 'asc'],
                ['assertion_type', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apiTargetChanged(MonitorApis $check, array $data, bool $assertionsChanged): bool
    {
        if (! $check->exists || $check->trashed()) {
            return false;
        }

        return $check->url !== $data['url']
            || $check->http_method !== $data['http_method']
            || (int) $check->expected_status !== (int) $data['expected_status']
            || $check->headers != $data['headers']
            || $check->request_body_type != $data['request_body_type']
            || $this->normalizeRequestBodyForComparison($check->request_body) != $this->normalizeRequestBodyForComparison($data['request_body'])
            || $check->timeout_seconds != $data['timeout_seconds']
            || $check->max_response_time_ms != $data['max_response_time_ms']
            || $assertionsChanged;
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
     * @return array<string, mixed>
     */
    private function awaitingLiveHealthAttributes(): array
    {
        return [
            'current_status' => 'unknown',
            'status_summary' => null,
            'diagnostic_queued_at' => null,
        ];
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
                    'expected_value' => array_key_exists('expected_value', $assertion) && $assertion['expected_value'] !== null
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
