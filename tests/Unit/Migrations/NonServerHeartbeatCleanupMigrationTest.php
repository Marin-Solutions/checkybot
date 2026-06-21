<?php

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('migration cleans synthetic stale data recalculates health drops non server heartbeat schema and preserves servers', function () {
    $migration = require database_path('migrations/2026_05_15_153348_clean_fake_stale_data_and_remove_non_server_heartbeat_schema.php');
    $migration->down();

    $user = User::factory()->create();
    $project = Project::factory()->create(['created_by' => $user->id]);

    $api = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'project_id' => $project->id,
        'current_status' => 'danger',
        'status_summary' => 'Heartbeat expired',
        'last_heartbeat_at' => now()->subHour(),
        'stale_at' => now()->subMinutes(30),
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $api->id,
        'status' => 'danger',
        'summary' => 'No scheduled API check completed within the expected 15m interval.',
        'created_at' => now()->subMinutes(20),
    ]);
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $api->id,
        'is_success' => true,
        'status' => 'healthy',
        'summary' => 'API recovered',
        'created_at' => now(),
    ]);

    $pendingApi = MonitorApis::factory()->create([
        'created_by' => $user->id,
        'project_id' => $project->id,
        'current_status' => 'danger',
        'status_summary' => 'Heartbeat expired',
    ]);

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'project_id' => $project->id,
        'current_status' => 'danger',
        'status_summary' => 'Heartbeat expired',
        'last_heartbeat_at' => now()->subHour(),
        'stale_at' => now()->subMinutes(30),
    ]);
    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'status' => 'danger',
        'summary' => 'No heartbeat received within the expected 15m interval.',
        'created_at' => now()->subMinutes(20),
    ]);
    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'status' => 'warning',
        'summary' => 'Certificate expires soon',
        'created_at' => now(),
    ]);

    $component = ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'created_by' => $user->id,
        'current_status' => 'danger',
        'summary' => 'Heartbeat expired',
        'last_heartbeat_at' => now()->subHour(),
        'is_stale' => true,
        'stale_detected_at' => now()->subMinutes(30),
    ]);
    $api->forceFill(['project_component_id' => $component->id])->save();
    DB::table('project_component_heartbeats')->insert([
        'project_component_id' => $component->id,
        'component_name' => $component->name,
        'status' => 'danger',
        'event' => 'stale',
        'summary' => 'Heartbeat expired',
        'observed_at' => now()->subMinutes(30),
        'created_at' => now()->subMinutes(30),
        'updated_at' => now()->subMinutes(30),
    ]);

    $server = Server::factory()->create([
        'created_by' => $user->id,
        'last_reporter_seen_at' => now()->subMinute(),
    ]);

    $migration->up();

    expect(DB::table('monitor_api_results')->where('summary', 'No scheduled API check completed within the expected 15m interval.')->exists())->toBeFalse()
        ->and(DB::table('website_log_history')->where('summary', 'No heartbeat received within the expected 15m interval.')->exists())->toBeFalse()
        ->and(Schema::hasTable('project_component_heartbeats'))->toBeFalse()
        ->and(Schema::hasColumn('monitor_apis', 'last_heartbeat_at'))->toBeFalse()
        ->and(Schema::hasColumn('monitor_apis', 'awaiting_heartbeat_since'))->toBeFalse()
        ->and(Schema::hasColumn('monitor_apis', 'stale_at'))->toBeFalse()
        ->and(Schema::hasColumn('websites', 'last_heartbeat_at'))->toBeFalse()
        ->and(Schema::hasColumn('websites', 'awaiting_heartbeat_since'))->toBeFalse()
        ->and(Schema::hasColumn('websites', 'stale_at'))->toBeFalse()
        ->and(Schema::hasColumn('project_components', 'last_heartbeat_at'))->toBeFalse()
        ->and(Schema::hasColumn('project_components', 'stale_detected_at'))->toBeFalse()
        ->and(Schema::hasColumn('project_components', 'is_stale'))->toBeFalse()
        ->and($api->fresh()->current_status)->toBe('healthy')
        ->and($pendingApi->fresh()->current_status)->toBe('pending')
        ->and($website->fresh()->current_status)->toBe('warning')
        ->and($component->fresh()->current_status)->toBe('healthy')
        ->and(Schema::hasColumn('servers', 'last_reporter_seen_at'))->toBeTrue()
        ->and($server->fresh()->last_reporter_seen_at)->not->toBeNull();
});
