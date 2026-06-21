<?php

namespace App\Services;

use App\Enums\RunSource;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Support\ApiMonitorEvidenceRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApiMonitorExecutionService
{
    public function __construct(
        private readonly PackageHealthStatusService $statusService,
    ) {}

    /**
     * Run a real API check and persist the result row.
     *
     * @param  bool  $onDemand  When true, the run is labeled as a manual run in history.
     * @return array{result: MonitorApiResult, status: string, summary: string, previous_status: string|null}
     */
    public function execute(MonitorApis $monitor, bool $onDemand = false, bool $scheduled = false): array
    {
        $startTime = microtime(true);
        try {
            $rawResult = MonitorApis::testApi([
                'id' => $monitor->id,
                'url' => $monitor->url,
                'method' => $monitor->http_method,
                'data_path' => $monitor->data_path,
                'headers' => $monitor->headers,
                'request_body_type' => $monitor->request_body_type,
                'request_body' => $monitor->request_body,
                'title' => $monitor->title,
                'expected_status' => $monitor->expected_status,
                'timeout_seconds' => $monitor->timeout_seconds,
                'max_response_time_ms' => $monitor->max_response_time_ms,
                MonitorApis::SCHEDULED_RUN_KEY => $scheduled,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Recording failed API monitor execution as check evidence.', [
                'monitor_id' => $monitor->id,
                'monitor_title' => $monitor->title,
                'exception' => $exception::class,
            ]);

            $rawResult = $this->failedExecutionResult($exception);
        }

        $status = $this->statusService->apiStatusFromResult($rawResult, $monitor->expected_status);
        $summary = $rawResult['summary'] ?? $this->statusService->summaryForApi($rawResult, $monitor->expected_status);
        [$result, $previousStatus] = $this->persistResult(
            $monitor,
            $rawResult,
            $startTime,
            $status,
            $summary,
            $onDemand ? RunSource::OnDemand : RunSource::Scheduled,
        );

        return [
            'result' => $result,
            'status' => $status,
            'summary' => $summary,
            'previous_status' => $previousStatus,
        ];
    }

    /**
     * Persist a failed result for failures that happen outside the HTTP
     * execution path, such as worker timeouts or queue-layer exceptions.
     *
     * @return array{result: MonitorApiResult, status: string, summary: string, previous_status: string|null}
     */
    public function recordScheduledFailure(MonitorApis $monitor, ?Throwable $exception): array
    {
        $startTime = microtime(true);
        $rawResult = $this->failedExecutionResult(
            $exception,
            'API monitor run failed before the scheduled check could complete.',
        );

        $status = $this->statusService->apiStatusFromResult($rawResult, $monitor->expected_status);
        $summary = $rawResult['summary'];

        [$result, $previousStatus] = $this->persistResult(
            $monitor,
            $rawResult,
            $startTime,
            $status,
            $summary,
            RunSource::Scheduled,
        );

        return [
            'result' => $result,
            'status' => $status,
            'summary' => $summary,
            'previous_status' => $previousStatus,
        ];
    }

    /**
     * @return array{0: MonitorApiResult, 1: string|null}
     */
    private function persistResult(
        MonitorApis $monitor,
        array $rawResult,
        float $startTime,
        string $status,
        string $summary,
        RunSource $runSource,
    ): array {
        return DB::transaction(function () use ($monitor, $rawResult, $startTime, $status, $summary, $runSource): array {
            $lockedMonitor = MonitorApis::query()
                ->whereKey($monitor->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previousStatus = $lockedMonitor->current_status;

            $result = MonitorApiResult::recordResult(
                $lockedMonitor,
                $rawResult,
                $startTime,
                $status,
                $summary,
                $runSource,
            );

            $lockedMonitor->forceFill([
                'current_status' => $status,
                'status_summary' => $summary,
            ])->save();

            $monitor->setRawAttributes($lockedMonitor->getAttributes(), true);
            $monitor->syncOriginal();

            return [$result, $previousStatus];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function failedExecutionResult(?Throwable $exception, string $summary = 'API monitor run failed before completing the check.'): array
    {
        $message = ApiMonitorEvidenceRedactor::redactTransportErrorMessage($exception?->getMessage());
        $error = $exception
            ? trim($exception::class.': '.($message ?? 'Unknown API monitor execution failure'))
            : 'Unknown API monitor execution failure';

        return [
            'code' => 0,
            'body' => null,
            'raw_body' => null,
            'assertions' => [],
            'error' => $error,
            'request_headers' => [],
            'response_headers' => [],
            'transport_error_type' => 'unknown',
            'transport_error_message' => $message,
            'transport_error_code' => $exception ? (int) $exception->getCode() : null,
            'summary' => $summary,
        ];
    }
}
