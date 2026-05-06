<?php

use App\Models\MonitorApis;
use App\Models\Project;
use App\Models\User;
use App\Services\CheckybotControlService;
use App\Services\PackageSyncService;
use Illuminate\Validation\ValidationException;

test('control service converts invalid schedules into validation errors', function () {
    $user = User::factory()->create();

    Project::factory()->create([
        'created_by' => $user->id,
        'package_key' => 'scrappa',
        'base_url' => 'https://api.scrappa.test',
    ]);

    try {
        app(CheckybotControlService::class)->upsertCheck($user, 'scrappa', [
            'key' => 'search-health',
            'name' => 'Search health',
            'url' => '/health',
            'schedule' => 'every_friday',
        ]);

        $this->fail('Expected a validation exception for an invalid schedule.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('schedule')
            ->and($exception->errors()['schedule'][0])->toContain('Invalid interval format: every_friday');
    }
});

test('package sync service converts invalid schedules into validation errors', function () {
    $user = User::factory()->create();

    try {
        app(PackageSyncService::class)->sync($user, [
            'project' => [
                'key' => 'scrappa',
                'name' => 'Scrappa',
                'environment' => 'production',
                'base_url' => 'https://api.scrappa.test',
            ],
            'checks' => [
                [
                    'key' => 'search-health',
                    'type' => 'api',
                    'name' => 'Search health',
                    'method' => 'GET',
                    'url' => '/health',
                    'schedule' => 'every_friday',
                ],
            ],
        ]);

        $this->fail('Expected a validation exception for an invalid schedule.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('schedule')
            ->and($exception->errors()['schedule'][0])->toContain('Invalid interval format: every_friday');

        $this->assertDatabaseMissing('projects', [
            'created_by' => $user->id,
            'package_key' => 'scrappa',
        ]);
    }
});

test('control service defaults missing schedules to safe api interval', function () {
    $user = User::factory()->create();

    Project::factory()->create([
        'created_by' => $user->id,
        'package_key' => 'scrappa',
        'base_url' => 'https://api.scrappa.test',
    ]);

    app(CheckybotControlService::class)->upsertCheck($user, 'scrappa', [
        'key' => 'search-health',
        'name' => 'Search health',
        'url' => '/health',
    ]);

    $monitor = MonitorApis::query()->where('package_name', 'search-health')->sole();

    expect($monitor->package_schedule)->toBe('5m')
        ->and($monitor->package_interval)->toBe('5m');
});

test('package sync service defaults missing api schedules to safe interval', function () {
    $user = User::factory()->create();

    app(PackageSyncService::class)->sync($user, [
        'project' => [
            'key' => 'scrappa',
            'name' => 'Scrappa',
            'environment' => 'production',
            'base_url' => 'https://api.scrappa.test',
        ],
        'checks' => [
            [
                'key' => 'search-health',
                'type' => 'api',
                'name' => 'Search health',
                'method' => 'GET',
                'url' => '/health',
            ],
        ],
    ]);

    $monitor = MonitorApis::query()->where('package_name', 'search-health')->sole();

    expect($monitor->package_schedule)->toBe('5m')
        ->and($monitor->package_interval)->toBe('5m');
});
