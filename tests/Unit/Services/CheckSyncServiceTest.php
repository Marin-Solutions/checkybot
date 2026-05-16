<?php

use App\Models\MonitorApiAssertion;
use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\ProjectComponent;
use App\Models\Website;
use App\Services\CheckSyncService;
use Illuminate\Support\Facades\DB;

test('sync checks preloads package resources without per-item lookup queries', function () {
    $project = Project::factory()->create();
    ProjectComponent::factory()->create([
        'project_id' => $project->id,
        'name' => 'database',
        'created_by' => $project->created_by,
    ]);

    Website::factory()->create([
        'project_id' => $project->id,
        'name' => 'Uptime Existing',
        'url' => 'https://uptime-existing.test',
        'source' => 'package',
        'package_name' => 'uptime-existing',
        'package_interval' => '5m',
        'created_by' => $project->created_by,
    ]);

    $existingApi = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'API Existing',
        'url' => 'https://api-existing.test',
        'source' => 'package',
        'package_name' => 'api-existing',
        'package_interval' => '5m',
        'created_by' => $project->created_by,
    ]);

    $anotherExistingApi = MonitorApis::factory()->create([
        'project_id' => $project->id,
        'title' => 'API Existing Secondary',
        'url' => 'https://api-existing-secondary.test',
        'source' => 'package',
        'package_name' => 'api-existing-secondary',
        'package_interval' => '5m',
        'created_by' => $project->created_by,
    ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $existingApi->id,
        'data_path' => 'data.status',
        'assertion_type' => 'value_compare',
        'comparison_operator' => '=',
        'expected_value' => 'ok',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    MonitorApiAssertion::factory()->create([
        'monitor_api_id' => $anotherExistingApi->id,
        'data_path' => 'data.ready',
        'assertion_type' => 'exists',
        'comparison_operator' => null,
        'expected_value' => null,
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $payload = [
        'uptime_checks' => [
            ['name' => 'uptime-existing', 'url' => 'https://uptime-existing.test', 'interval' => '5m', 'component' => 'database'],
            ['name' => 'uptime-new', 'url' => 'https://uptime-new.test', 'interval' => '10m', 'component' => 'database'],
        ],
        'ssl_checks' => [
            ['name' => 'ssl-new', 'url' => 'https://ssl-new.test', 'interval' => '30m', 'component' => 'database'],
        ],
        'api_checks' => [
            [
                'name' => 'api-existing',
                'url' => 'https://api-existing.test',
                'interval' => '5m',
                'component' => 'database',
                'assertions' => [
                    [
                        'data_path' => 'data.status',
                        'assertion_type' => 'value_compare',
                        'comparison_operator' => '=',
                        'expected_value' => 'ok',
                        'sort_order' => 1,
                        'is_active' => true,
                    ],
                ],
            ],
            [
                'name' => 'api-existing-secondary',
                'url' => 'https://api-existing-secondary.test',
                'interval' => '5m',
                'component' => 'database',
                'assertions' => [
                    [
                        'data_path' => 'data.ready',
                        'assertion_type' => 'exists',
                        'sort_order' => 1,
                        'is_active' => true,
                    ],
                ],
            ],
            ['name' => 'api-new', 'url' => 'https://api-new.test', 'interval' => '15m', 'component' => 'database', 'assertions' => []],
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
    $assertionPerItemSelectLookups = $queries->filter(
        fn (string $query) => preg_match('/^select\s+.*from\s+["`]?monitor_api_assertions["`]?\s+where\s+.*["`]?monitor_api_id["`]?\s*=\s*\?/i', $query) === 1
    );
    $componentPerItemLookups = $queries->filter(
        fn (string $query) => preg_match('/from\s+["`]?project_components["`]?\s+where\s+.*["`]?name["`]?\s*=\s*\?/i', $query) === 1
    );

    expect($summary['uptime_checks']['created'])->toBe(1);
    expect($summary['api_checks']['created'])->toBe(1);
    expect($websitePerItemLookups)->toHaveCount(0);
    expect($apiPerItemLookups)->toHaveCount(0);
    expect($assertionPerItemSelectLookups)->toHaveCount(0);
    expect($componentPerItemLookups)->toHaveCount(0);

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
