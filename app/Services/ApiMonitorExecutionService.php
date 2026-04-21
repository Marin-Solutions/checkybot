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
     * @return array{result: MonitorApiResult, status: string, summary: string, previous_status: string|null}
     */
    public function execute(MonitorApis $monitor): array
    {
        $startTime = microtime(true);
        $rawResult = MonitorApis::testApi([
            'id' => $monitor->id,
            'url' => $monitor->url,
            'method' => $monitor->http_method,
            'data_path' => $monitor->data_path,
            'headers' => $monitor->headers,
            'title' => $monitor->title,
            'expected_status' => $monitor->expected_status,
            'timeout_seconds' => $monitor->timeout_seconds,
        ]);

        $status = $this->statusService->apiStatusFromResult($rawResult);
        $summary = $this->statusService->summaryForApi($rawResult);
        $previousStatus = $monitor->current_status;

        $result = MonitorApiResult::recordResult($monitor, $rawResult, $startTime, $status, $summary);

        $monitor->forceFill([
            'current_status' => $status,
            'last_heartbeat_at' => now(),
            'stale_at' => null,
            'status_summary' => $summary,
        ])->save();

        return [
            'result' => $result,
            'status' => $status,
            'summary' => $summary,
            'previous_status' => $previousStatus,
        ];
    }
}
