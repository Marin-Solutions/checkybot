<?php

namespace App\Jobs;

use App\Models\MonitorApis;
use App\Services\ApiMonitorExecutionService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunApiMonitorDiagnosticJob implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 420;

    public function __construct(
        public MonitorApis $monitor,
    ) {}

    public function handle(ApiMonitorExecutionService $executionService): void
    {
        if ($this->batch()?->cancelled()) {
            $this->clearQueuedDiagnostic();

            return;
        }

        try {
            $executionService->execute($this->monitor, onDemand: true);
        } finally {
            $this->clearQueuedDiagnostic();
        }
    }

    private function clearQueuedDiagnostic(): void
    {
        MonitorApis::query()
            ->whereKey($this->monitor->getKey())
            ->update(['diagnostic_queued_at' => null]);
    }
}
