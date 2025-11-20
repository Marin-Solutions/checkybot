<?php

namespace Tests\Feature\Api\V1;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

class SimpleHttpTest extends BaseTestCase
{
    use RefreshDatabase;

    public function createApplication()
    {
        $app = require __DIR__ . '/../../../../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    public function test_http_request_persists_data(): void
    {
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
    }
}
