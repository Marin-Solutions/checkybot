<?php

use App\Jobs\RunApiMonitorDiagnosticJob;
use App\Models\MonitorApis;
use App\Services\ApiMonitorExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;

test('run api monitor diagnostic job is queued with enough time for configured retries', function () {
    $job = new RunApiMonitorDiagnosticJob(new MonitorApis);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->timeout)->toBe(420);
});

test('run api monitor diagnostic job skips monitors disabled after dispatch', function () {
    $monitor = MonitorApis::factory()->create([
        'is_enabled' => true,
        'diagnostic_queued_at' => now(),
    ]);

    $job = new RunApiMonitorDiagnosticJob($monitor);

    $monitor->update(['is_enabled' => false]);

    $executionService = Mockery::mock(ApiMonitorExecutionService::class);
    $executionService->shouldNotReceive('execute');

    $job->handle($executionService);

    expect($monitor->refresh()->diagnostic_queued_at)->toBeNull();
});
