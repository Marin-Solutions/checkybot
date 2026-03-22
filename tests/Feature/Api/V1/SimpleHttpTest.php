<?php

use App\Models\ApiKey;
use App\Models\Project;
use App\Models\User;

test('http request persists data', function () {
    $user = User::factory()->create();
    $apiKey = ApiKey::factory()->create(['user_id' => $user->id]);
    $project = Project::factory()->create(['created_by' => $user->id]);

    $response = $this->withToken($apiKey->key)->postJson(
        "/api/v1/projects/{$project->id}/checks/sync",
        [
            'uptime_checks' => [
                [
                    'name' => 'test-check',
                    'url' => 'https://simple-test.com',
                    'interval' => '5m',
                ],
            ],
            'ssl_checks' => [],
            'api_checks' => [],
        ]
    );

    $response->assertStatus(200);

    // Check if data was persisted
    $this->assertDatabaseHas('websites', [
        'name' => 'test-check',
    ]);
});
