<?php

namespace App\Services;

use App\Enums\RunSource;
use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use Illuminate\Support\Facades\DB;

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
}
