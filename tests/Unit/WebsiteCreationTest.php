<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Tests\TestCase;

class WebsiteCreationTest extends TestCase
{
    public function test_can_create_website_directly(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['created_by' => $user->id]);

        $website = Website::create([
            'project_id' => $project->id,
            'name' => 'test-website',
            'url' => 'https://test-unique-url.com',
            'description' => '',
            'uptime_check' => true,
            'uptime_interval' => 5,
            'source' => 'package',
            'package_name' => 'test-website',
            'package_interval' => '5m',
            'created_by' => $user->id,
        ]);

        $this->assertNotNull($website->id);
        $this->assertDatabaseHas('websites', [
            'name' => 'test-website',
            'url' => 'https://test-unique-url.com',
        ]);
    }
}
