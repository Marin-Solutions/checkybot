<?php

use App\Jobs\RunApiMonitorDiagnosticJob;
use App\Models\MonitorApis;
use Illuminate\Contracts\Queue\ShouldQueue;

test('run api monitor diagnostic job is queued with enough time for configured retries', function () {
    $job = new RunApiMonitorDiagnosticJob(new MonitorApis);

    expect($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job->timeout)->toBe(420);
});
