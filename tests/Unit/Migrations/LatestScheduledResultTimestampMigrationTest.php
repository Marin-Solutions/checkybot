<?php

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('migration backfills the latest scheduled timestamps and ignores on demand results', function () {
    $scheduledWebsiteAt = now()->subHours(2)->startOfSecond();
    $scheduledApiAt = now()->subHours(3)->startOfSecond();

    $website = Website::factory()->create();
    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'created_at' => $scheduledWebsiteAt,
        'updated_at' => $scheduledWebsiteAt,
    ]);
    WebsiteLogHistory::factory()->onDemand()->create([
        'website_id' => $website->id,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $monitor = MonitorApis::factory()->create();
    MonitorApiResult::factory()->create([
        'monitor_api_id' => $monitor->id,
        'created_at' => $scheduledApiAt,
        'updated_at' => $scheduledApiAt,
    ]);
    MonitorApiResult::factory()->onDemand()->create([
        'monitor_api_id' => $monitor->id,
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    DB::table('websites')->where('id', $website->id)->update(['latest_scheduled_result_at' => null]);
    DB::table('monitor_apis')->where('id', $monitor->id)->update(['latest_scheduled_result_at' => null]);

    $migration = require database_path('migrations/2026_07_28_000001_cache_latest_scheduled_result_timestamps.php');
    $migration->down();
    $migration->up();

    expect(Schema::hasColumn('websites', 'latest_scheduled_result_at'))->toBeTrue()
        ->and(Schema::hasColumn('monitor_apis', 'latest_scheduled_result_at'))->toBeTrue()
        ->and($website->fresh()->latest_scheduled_result_at->equalTo($scheduledWebsiteAt))->toBeTrue()
        ->and($monitor->fresh()->latest_scheduled_result_at->equalTo($scheduledApiAt))->toBeTrue();
});
