<?php

use App\Models\Project;
use App\Models\User;

test('http request persists data', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $response = $this->actingAs($user)->postJson("/api/v1/projects/{$project->id}/checks/sync", [
        'uptime_checks' => [
            [
                'name' => 'test-check',
                'url' => 'https://simple-test.com',
                'interval' => '5m',
            ],
        ],
        'ssl_checks' => [],
        'api_checks' => [],
    ]);

    dump('Response status:', $response->status());
    dump('Response body:', $response->json());

    $response->assertStatus(200);

    // Check if data was persisted
    $this->assertDatabaseHas('websites', [
        'name' => 'test-check',
    ]);
});
