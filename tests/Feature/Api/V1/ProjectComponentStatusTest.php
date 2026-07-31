<?php

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);
    $this->project = Project::factory()->create(['created_by' => $this->user->id]);
    $this->component = ProjectComponent::factory()->create([
        'project_id' => $this->project->id,
        'created_by' => $this->user->id,
        'name' => 'serp-data-lake',
        'source' => 'package',
        'current_status' => 'unknown',
        'last_reported_status' => 'unknown',
        'status_observed_at' => null,
    ]);
});

test('component status requires bearer authentication', function () {
    $this->postJson(componentStatusUrl($this->project, $this->component))
        ->assertUnauthorized();

    $this->withToken('ck_invalid')
        ->postJson(componentStatusUrl($this->project, $this->component))
        ->assertUnauthorized();
});

test('component status validates the declared component and owner', function () {
    $otherUser = User::factory()->create();
    $otherKey = ApiKey::factory()->create(['user_id' => $otherUser->id]);
    $otherProject = Project::factory()->create(['created_by' => $otherUser->id]);

    $this->withToken($this->apiKey->key)
        ->postJson(componentStatusUrl($this->project, 'not-declared'), validComponentStatusPayload())
        ->assertNotFound();

    $this->withToken($otherKey->key)
        ->postJson(componentStatusUrl($this->project, $this->component), validComponentStatusPayload())
        ->assertForbidden();

    $this->withToken($this->apiKey->key)
        ->postJson(componentStatusUrl($otherProject, 'serp-data-lake'), validComponentStatusPayload())
        ->assertForbidden();
});

test('component status persists observations and maps failure to danger', function () {
    $payload = [
        'status' => 'failure',
        'observed_at' => '2026-07-31T12:34:56Z',
        'message' => 'Refresh backlog exceeded the failure threshold.',
        'metrics' => [
            'due' => 101,
            'oldest_overdue_age_minutes' => 121,
        ],
    ];

    $this->withToken($this->apiKey->key)
        ->postJson(componentStatusUrl($this->project, $this->component), $payload)
        ->assertOk()
        ->assertJsonPath('message', 'Component status reported successfully')
        ->assertJsonPath('component.component_key', 'serp-data-lake')
        ->assertJsonPath('component.status', 'danger')
        ->assertJsonPath('component.message', $payload['message'])
        ->assertJsonPath('component.metrics.due', 101);

    $component = $this->component->fresh();

    expect($component->current_status)->toBe('danger')
        ->and($component->last_reported_status)->toBe('danger')
        ->and($component->status_observed_at?->toIso8601String())->toBe('2026-07-31T12:34:56+00:00')
        ->and($component->derivedCurrentStatus())->toBe('danger');

    $this->assertDatabaseHas('project_component_heartbeats', [
        'project_component_id' => $this->component->id,
        'component_name' => 'serp-data-lake',
        'status' => 'danger',
        'event' => 'status',
        'summary' => $payload['message'],
    ]);
});

test('component status preserves healthy and warning states', function () {
    foreach ([
        ['status' => 'healthy', 'stored' => 'healthy', 'at' => '2026-07-31T12:34:56Z'],
        ['status' => 'warning', 'stored' => 'warning', 'at' => '2026-07-31T12:35:56Z'],
    ] as $case) {
        $payload = validComponentStatusPayload([
            'status' => $case['status'],
            'observed_at' => $case['at'],
        ]);

        $this->withToken($this->apiKey->key)
            ->postJson(componentStatusUrl($this->project, $this->component), $payload)
            ->assertOk()
            ->assertJsonPath('component.status', $case['stored']);
    }

    expect($this->component->fresh()->current_status)->toBe('warning')
        ->and($this->component->heartbeats()->count())->toBe(2);
});

test('component status rejects declaration-shaped, invalid, and unbounded payload fields', function () {
    $payload = validComponentStatusPayload();

    $this->withToken($this->apiKey->key)
        ->postJson(componentStatusUrl($this->project, $this->component), $payload + ['extra' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['extra']);

    foreach ([
        ['status' => 'danger'],
        ['observed_at' => '2026-07-31 12:34:56'],
        ['observed_at' => '2026-07-31T12:34:56+02:00'],
        ['message' => "unsafe\nmessage"],
        ['message' => str_repeat('a', 501)],
        ['metrics' => ['not_allowed' => 1]],
        ['metrics' => ['due' => '1']],
        ['metrics' => ['due' => -1]],
        ['metrics' => ['due' => 1_000_000_001]],
    ] as $invalid) {
        $this->withToken($this->apiKey->key)
            ->postJson(componentStatusUrl($this->project, $this->component), array_replace($payload, $invalid))
            ->assertUnprocessable();
    }
});

test('component status does not allow declaration sync to carry runtime observations', function () {
    $this->withToken($this->apiKey->key)
        ->postJson("/api/v1/projects/{$this->project->id}/components/sync", [
            'declared_components' => [
                ['name' => 'serp-data-lake', 'interval' => '5m'],
            ],
            'components' => [
                ['name' => 'serp-data-lake', 'status' => 'failure'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['components']);
});

function componentStatusUrl(Project $project, ProjectComponent|string $component): string
{
    $componentKey = $component instanceof ProjectComponent ? $component->name : $component;

    return "/api/v1/projects/{$project->id}/components/{$componentKey}/status";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validComponentStatusPayload(array $overrides = []): array
{
    return array_replace([
        'status' => 'healthy',
        'observed_at' => '2026-07-31T12:34:56Z',
        'message' => 'Component is healthy.',
        'metrics' => ['count' => 1],
    ], $overrides);
}
