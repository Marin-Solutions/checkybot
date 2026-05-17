<?php

namespace App\Jobs;

use App\Models\MonitorApis;
use App\Services\ApiMonitorExecutionService;
use App\Services\HealthEventNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunScheduledApiMonitorJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 420;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 480;

    public function __construct(
        public MonitorApis $monitor,
    ) {}

    public function uniqueId(): string
    {
        return "api-monitor:{$this->monitor->getKey()}:scheduled";
    }

    public function handle(
        ApiMonitorExecutionService $executionService,
        HealthEventNotificationService $notificationService,
    ): void {
        $monitor = $this->monitor->fresh();

        if (! $monitor?->is_enabled) {
            return;
        }

        $this->monitor = $monitor;

        $execution = $executionService->execute($this->monitor);
        $status = $execution['status'];
        $summary = $execution['summary'];
        $previousStatus = $execution['previous_status'];

        $notificationService->notifyApiIfTransitioned($this->monitor, $previousStatus, $status, $summary);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Scheduled API monitor job failed before a controlled result could be recorded.', [
            'monitor_id' => $this->monitor->id,
            'monitor_title' => $this->monitor->title,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
