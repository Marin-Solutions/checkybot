<?php

use App\Http\Requests\SyncProjectChecksRequest;
use App\Models\ApiKey;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);
    $this->project = Project::factory()->create(['created_by' => $this->user->id]);
});

test('syncs a complete API check manifest at the supported capacity', function () {
    $obsoleteCheck = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'obsolete-api-check',
        'is_enabled' => true,
    ]);

    $apiChecks = apiCheckManifest(SyncProjectChecksRequest::MAX_API_CHECKS_PER_SYNC);

    $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'full_manifest' => true,
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => $apiChecks,
        ])
        ->assertOk()
        ->assertJsonPath('summary.api_checks.created', SyncProjectChecksRequest::MAX_API_CHECKS_PER_SYNC)
        ->assertJsonPath('summary.api_checks.updated', 0)
        ->assertJsonPath('summary.api_checks.deleted', 1);

    expect(MonitorApis::query()
        ->where('project_id', $this->project->id)
        ->where('source', 'package')
        ->whereNull('deleted_at')
        ->count())->toBe(SyncProjectChecksRequest::MAX_API_CHECKS_PER_SYNC)
        ->and($obsoleteCheck->fresh()->trashed())->toBeTrue();
});

test('rejects an API check manifest above the supported capacity without reconciling it', function () {
    $existingCheck = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'existing-api-check',
        'is_enabled' => true,
    ]);

    $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'full_manifest' => true,
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => apiCheckManifest(SyncProjectChecksRequest::MAX_API_CHECKS_PER_SYNC + 1),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['api_checks'])
        ->assertJsonPath(
            'errors.api_checks.0',
            'The api checks field must not have more than '.SyncProjectChecksRequest::MAX_API_CHECKS_PER_SYNC.' items.',
        );

    expect($existingCheck->fresh()->trashed())->toBeFalse()
        ->and(MonitorApis::query()
            ->where('project_id', $this->project->id)
            ->where('source', 'package')
            ->count())->toBe(1);
});

/**
 * @return array<int, array{name: string, url: string, interval: string}>
 */
function apiCheckManifest(int $count): array
{
    return array_map(
        fn (int $index): array => [
            'name' => "api-check-{$index}",
            'url' => "https://example.com/api/checks/{$index}",
            'interval' => '5m',
        ],
        range(1, $count),
    );
}
