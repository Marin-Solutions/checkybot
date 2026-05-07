<?php

namespace App\Jobs;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Services\ApiMonitorExecutionService;
use App\Services\HealthEventNotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Sentry\Laravel\Facade as Sentry;
use Throwable;

class RunScheduledApiMonitorJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 420;

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
                Sentry::configureScope(function (\Sentry\State\Scope $scope) use ($execution): void {
                    $scope->setContext('monitor', [
                        'monitor_id' => $this->monitor->id,
                        'monitor_title' => $this->monitor->title,
                        'url' => $this->monitor->url,
                        'data_path' => $this->monitor->data_path,
                        'result' => $execution,
                    ]);
                });

                throw new \Exception('Invalid API test result format - missing code');
            }

            $notificationService->notifyApiIfTransitioned($this->monitor, $previousStatus, $status, $summary);
        } catch (Throwable $e) {
            Log::error('Error checking API monitor: '.$e->getMessage(), [
                'monitor_id' => $this->monitor->id,
                'monitor_title' => $this->monitor->title,
            ]);

            Sentry::captureException($e);

            throw $e;
        }
    }
}
