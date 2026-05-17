<?php

namespace App\Jobs;

use App\Enums\RunSource;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Services\ApiMonitorExecutionService;
use App\Services\HealthEventNotificationService;
use App\Support\ApiMonitorEvidenceRedactor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class RunScheduledApiMonitorJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $backoff = 60;

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

        try {
            $execution = $executionService->execute($this->monitor);
            /** @var MonitorApiResult $result */
            $result = $execution['result'];
            $status = $execution['status'];
            $summary = $execution['summary'];
            $previousStatus = $execution['previous_status'];

            if (! isset($result->http_code)) {
                throw new UnexpectedValueException('Invalid API test result format - missing code');
            }
        } catch (Throwable $e) {
            Log::warning('Recording failed scheduled API monitor run: '.$e->getMessage(), [
                'monitor_id' => $this->monitor->id,
                'monitor_title' => $this->monitor->title,
                'exception' => $e::class,
            ]);

            $this->recordFailureResult($e, $notificationService);

            return;
        }

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

    private function recordFailureResult(Throwable $exception, HealthEventNotificationService $notificationService): void
    {
        $summary = 'API monitor run failed before completing the scheduled check.';
        $message = ApiMonitorEvidenceRedactor::redactTransportErrorMessage($exception->getMessage());
        $result = [
            'code' => 0,
            'body' => null,
            'raw_body' => null,
            'assertions' => [],
            'error' => trim($exception::class.': '.($message ?? 'Unknown scheduled monitor failure')),
            'request_headers' => [],
            'response_headers' => [],
            'transport_error_type' => 'unknown',
            'transport_error_message' => $message,
            'transport_error_code' => (int) $exception->getCode(),
        ];

        [$lockedMonitor, $previousStatus] = DB::transaction(function () use ($result, $summary): array {
            $lockedMonitor = MonitorApis::query()
                ->whereKey($this->monitor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previousStatus = $lockedMonitor->current_status;

            MonitorApiResult::recordResult(
                $lockedMonitor,
                $result,
                microtime(true),
                'danger',
                $summary,
                RunSource::Scheduled,
            );

            $lockedMonitor->forceFill([
                'current_status' => 'danger',
                'status_summary' => $summary,
            ])->save();

            return [$lockedMonitor, $previousStatus];
        });

        $this->monitor = $lockedMonitor;

        $notificationService->notifyApiIfTransitioned($lockedMonitor, $previousStatus, 'danger', $summary);
    }
}
