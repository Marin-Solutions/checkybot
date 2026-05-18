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
use Psr\Log\LogLevel;
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
        try {
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
        } catch (Throwable $exception) {
            $this->recordQueueFailure(
                $exception,
                'Scheduled API monitor job recovered a queue/runtime failure as monitor evidence.',
                LogLevel::WARNING,
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->recordQueueFailure(
            $exception,
            'Scheduled API monitor job failed before a controlled result could be recorded.',
            LogLevel::ERROR,
        );
    }

    private function recordQueueFailure(?Throwable $exception, string $message, string $level): void
    {
        $monitor = $this->monitor->fresh(['latestScheduledResult']);

        Log::log($level, $message, [
            'monitor_id' => $this->monitor->id,
            'monitor_title' => $this->monitor->title,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
        ]);

        if (! $monitor?->is_enabled) {
            return;
        }

        $dispatchedAt = Carbon::parse($this->dispatchedAt ?? now()->toISOString())->startOfSecond();
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
