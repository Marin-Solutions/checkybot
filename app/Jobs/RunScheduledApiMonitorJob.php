<?php

namespace App\Jobs;

use App\Models\MonitorApis;
use App\Services\ApiMonitorExecutionService;
use App\Services\HealthEventNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunScheduledApiMonitorJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 420;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 480;

    public string $dispatchedAt;

    public function __construct(
        public MonitorApis $monitor,
    ) {
        $this->dispatchedAt = now()->toISOString();
    }

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

        $execution = $executionService->execute($this->monitor, scheduled: true);
        $status = $execution['status'];
        $summary = $execution['summary'];
        $previousStatus = $execution['previous_status'];

        $notificationService->notifyApiIfTransitioned($this->monitor, $previousStatus, $status, $summary);
    }

    public function failed(?Throwable $exception): void
    {
        $monitor = $this->monitor->fresh(['latestScheduledResult']);

        Log::error('Scheduled API monitor job failed before a controlled result could be recorded.', [
            'monitor_id' => $this->monitor->id,
            'monitor_title' => $this->monitor->title,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);

        if (! $monitor?->is_enabled) {
            return;
        }

        $dispatchedAt = Carbon::parse($this->dispatchedAt ?? now()->toISOString());
        if ($monitor->latestScheduledResult?->created_at?->greaterThanOrEqualTo($dispatchedAt)) {
            return;
        }

        $execution = app(ApiMonitorExecutionService::class)->recordScheduledFailure($monitor, $exception);

        app(HealthEventNotificationService::class)->notifyApiIfTransitioned(
            $monitor,
            $execution['previous_status'],
            $execution['status'],
            $execution['summary'],
        );
    }
}
