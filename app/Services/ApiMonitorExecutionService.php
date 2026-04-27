<?php

namespace App\Services;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;

class ApiMonitorExecutionService
{
    public function __construct(
        private readonly PackageHealthStatusService $statusService,
    ) {}

    /**
     * Run a real API check and persist the result row.
     *
     * @param  bool  $onDemand  When true, the run is treated as an operator-triggered diagnostic:
     *                          the result is recorded but the monitor's live status fields
     *                          (`current_status`, `last_heartbeat_at`, `stale_at`, `status_summary`)
     *                          are left untouched so the scheduler's transition-based alerting baseline
     *                          stays accurate.
     * @return array{result: MonitorApiResult, status: string, summary: string, previous_status: string|null}
     */
    public function execute(MonitorApis $monitor, bool $onDemand = false): array
    {
        $startTime = microtime(true);
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

        $status = $this->statusService->apiStatusFromResult($rawResult, $monitor->expected_status);
        $summary = $this->statusService->summaryForApi($rawResult, $monitor->expected_status);
        $previousStatus = $monitor->current_status;

        $result = MonitorApiResult::recordResult($monitor, $rawResult, $startTime, $status, $summary);

        if (! $onDemand) {
            $monitor->forceFill([
                'current_status' => $status,
                'last_heartbeat_at' => now(),
                'stale_at' => null,
                'status_summary' => $summary,
            ])->save();
        }

        return [
            'result' => $result,
            'status' => $status,
            'summary' => $summary,
            'previous_status' => $previousStatus,
        ];
    }
}
