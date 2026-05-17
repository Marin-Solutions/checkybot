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
    public function execute(MonitorApis $monitor, bool $onDemand = false): array
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
        [$result, $previousStatus] = DB::transaction(function () use ($monitor, $onDemand, $rawResult, $startTime, $status, $summary): array {
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
                $onDemand ? RunSource::OnDemand : RunSource::Scheduled,
            );

            $lockedMonitor->forceFill([
                'current_status' => $status,
                'status_summary' => $summary,
            ])->save();

            $monitor->setRawAttributes($lockedMonitor->getAttributes(), true);
            $monitor->syncOriginal();

            return [$result, $previousStatus];
        });

        return [
            'result' => $result,
            'status' => $status,
            'summary' => $summary,
            'previous_status' => $previousStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedExecutionResult(Throwable $exception): array
    {
        $message = ApiMonitorEvidenceRedactor::redactTransportErrorMessage($exception->getMessage());

        return [
            'code' => 0,
            'body' => null,
            'raw_body' => null,
            'assertions' => [],
            'error' => trim($exception::class.': '.($message ?? 'Unknown API monitor execution failure')),
            'request_headers' => [],
            'response_headers' => [],
            'transport_error_type' => 'unknown',
            'transport_error_message' => $message,
            'transport_error_code' => (int) $exception->getCode(),
            'summary' => 'API monitor run failed before completing the check.',
        ];
    }
}
