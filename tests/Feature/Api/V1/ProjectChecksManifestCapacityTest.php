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

test('does not impose an application-level API check manifest limit', function () {
    expect((new SyncProjectChecksRequest)->rules()['api_checks'])->toBe(['array']);
});

test('syncs a complete API check manifest larger than the previous limits', function () {
    $obsoleteCheck = MonitorApis::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'source' => 'package',
        'package_name' => 'obsolete-api-check',
        'is_enabled' => true,
    ]);

    $apiChecks = apiCheckManifest(300);

    $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/checks/sync", [
            'full_manifest' => true,
            'uptime_checks' => [],
            'ssl_checks' => [],
            'api_checks' => $apiChecks,
        ])
        ->assertOk()
        ->assertJsonPath('summary.api_checks.created', 300)
        ->assertJsonPath('summary.api_checks.updated', 0)
        ->assertJsonPath('summary.api_checks.deleted', 1);

    expect(MonitorApis::query()
        ->where('project_id', $this->project->id)
        ->where('source', 'package')
        ->whereNull('deleted_at')
        ->count())->toBe(300)
        ->and($obsoleteCheck->fresh()->trashed())->toBeTrue();
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
