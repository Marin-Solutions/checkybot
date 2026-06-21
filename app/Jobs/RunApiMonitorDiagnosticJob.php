<?php

namespace App\Jobs;

use App\Models\MonitorApis;
use App\Services\ApiMonitorExecutionService;
use App\Services\HealthEventNotificationService;
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

    public function handle(
        ApiMonitorExecutionService $executionService,
        HealthEventNotificationService $notificationService,
    ): void {
        if ($this->batch()?->cancelled()) {
            $this->clearQueuedDiagnostic();

            return;
        }

        $monitor = $this->monitor->fresh();

        if (! $monitor?->is_enabled) {
            $this->clearQueuedDiagnostic();

            return;
        }

        $this->monitor = $monitor;

        try {
            $execution = $executionService->execute($this->monitor, onDemand: true);

            $notificationService->notifyApiIfTransitioned(
                $this->monitor,
                $execution['previous_status'],
                $execution['status'],
                $execution['summary'],
            );
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
