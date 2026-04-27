<?php

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->apiKey = ApiKey::factory()->create(['user_id' => $this->user->id]);
});

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Checkout App',
        'environment' => 'production',
        'identity_endpoint' => 'https://checkout.example.com',
        'technology' => 'Laravel',
        'package_version' => '1.2.3',
    ], $overrides);
}

test('package registration attaches to the guided setup project shell on first install', function () {
    $project = Project::factory()->create([
        'created_by' => $this->user->id,
        'name' => 'Checkout App',
        'environment' => 'production',
        'technology' => null,
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/register', registrationPayload([
            'app_id' => $project->id,
        ]));

    $response->assertOk()
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.created', false);

    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'created_by' => $this->user->id,
        'identity_endpoint' => 'https://checkout.example.com',
        'technology' => 'Laravel',
        'package_version' => '1.2.3',
    ]);
});

test('package registration reuses an existing application identity for the same owner', function () {
    $project = Project::factory()->create([
        'created_by' => $this->user->id,
        'name' => 'Checkout App',
        'environment' => 'production',
        'identity_endpoint' => 'https://checkout.example.com',
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/register', registrationPayload());

    $response->assertOk()
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.created', false);

    expect(Project::query()->where('created_by', $this->user->id)->count())->toBe(1);

    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'package_version' => '1.2.3',
    ]);
});

test('package registration auto creates an application when no matching identity exists', function () {
    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/register', registrationPayload());

    $response->assertCreated()
        ->assertJsonPath('data.created', true);

    $projectId = $response->json('data.project_id');

    expect($projectId)->not->toBeNull();

    $this->assertDatabaseHas('projects', [
        'id' => $projectId,
        'created_by' => $this->user->id,
        'name' => 'Checkout App',
        'environment' => 'production',
        'identity_endpoint' => 'https://checkout.example.com',
        'technology' => 'Laravel',
        'package_version' => '1.2.3',
    ]);
});

test('package registration updates the stored sdk version on reconnect', function () {
    $project = Project::factory()->create([
        'created_by' => $this->user->id,
        'name' => 'Checkout App',
        'environment' => 'production',
        'identity_endpoint' => 'https://checkout.example.com',
        'package_version' => '1.2.3',
    ]);

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/register', registrationPayload([
            'package_version' => '1.2.4',
        ]));

    $response->assertOk()
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.created', false);

    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'package_version' => '1.2.4',
    ]);
});

test('package registration without sdk version keeps the stored version', function () {
    $project = Project::factory()->create([
        'created_by' => $this->user->id,
        'name' => 'Checkout App',
        'environment' => 'production',
        'identity_endpoint' => 'https://checkout.example.com',
        'package_version' => '1.2.3',
    ]);

    $payload = registrationPayload();
    unset($payload['package_version']);

    $response = $this->withToken($this->apiKey->key)
        ->postJson('/api/v1/package/register', $payload);

    $response->assertOk()
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.created', false);

    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'package_version' => '1.2.3',
    ]);
});

test('application identity is unique per owner and environment', function () {
    Project::factory()->create([
        'created_by' => $this->user->id,
        'environment' => 'production',
        'identity_endpoint' => 'https://checkout.example.com',
    ]);

    expect(fn () => Project::factory()->create([
        'created_by' => $this->user->id,
        'environment' => 'production',
        'identity_endpoint' => 'https://checkout.example.com',
    ]))->toThrow(QueryException::class);
});
