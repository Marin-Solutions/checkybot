<?php

use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\Website;
use App\Services\CheckSyncService;
use Illuminate\Support\Facades\DB;

test('sync checks preloads package resources without per-item lookup queries', function () {
    $project = Project::factory()->create();

    Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'Uptime Existing',
        'url' => 'https://uptime-existing.test',
        'source' => 'package',
        'package_name' => 'uptime-existing',
        'package_interval' => '5m',
        'created_by' => $project->created_by,
    ]);

    MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'API Existing',
        'url' => 'https://api-existing.test',
        'source' => 'package',
        'package_name' => 'api-existing',
        'package_interval' => '5m',
        'created_by' => $project->created_by,
    ]);

    $payload = [
        'uptime_checks' => [
            ['name' => 'uptime-existing', 'url' => 'https://uptime-existing.test', 'interval' => '5m'],
            ['name' => 'uptime-new', 'url' => 'https://uptime-new.test', 'interval' => '10m'],
        ],
        'ssl_checks' => [
            ['name' => 'ssl-new', 'url' => 'https://ssl-new.test', 'interval' => '30m'],
        ],
        'api_checks' => [
            ['name' => 'api-existing', 'url' => 'https://api-existing.test', 'interval' => '5m', 'assertions' => []],
            ['name' => 'api-new', 'url' => 'https://api-new.test', 'interval' => '15m', 'assertions' => []],
        ],
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();

    $summary = app(CheckSyncService::class)->syncChecks($project, $payload);

    $queries = collect(DB::getQueryLog())->pluck('query');
    $websitePerItemLookups = $queries->filter(
        fn (string $query) => preg_match('/from\s+["`]?websites["`]?\s+where\s+.*["`]?package_name["`]?\s*=\s*\?/i', $query) === 1
    );
    $apiPerItemLookups = $queries->filter(
        fn (string $query) => preg_match('/from\s+["`]?monitor_apis["`]?\s+where\s+.*["`]?package_name["`]?\s*=\s*\?/i', $query) === 1
    );

    expect($summary['uptime_checks']['created'])->toBe(1);
    expect($summary['api_checks']['created'])->toBe(1);
    expect($websitePerItemLookups)->toHaveCount(0);
    expect($apiPerItemLookups)->toHaveCount(0);

    assertDatabaseHas('websites', [
        'project_id' => $project->id,
        'package_name' => 'uptime-new',
        'source' => 'package',
    ]);

    assertDatabaseHas('monitor_apis', [
        'project_id' => $project->id,
        'package_name' => 'api-new',
        'source' => 'package',
    ]);
});
