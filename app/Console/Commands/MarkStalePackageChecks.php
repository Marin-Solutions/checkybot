<?php

namespace App\Console\Commands;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\HealthEventNotificationService;
use App\Services\PackageHealthStatusService;
use Illuminate\Console\Command;

class MarkStalePackageChecks extends Command
{
    protected $signature = 'app:mark-stale-package-checks';

    protected $description = 'Mark overdue package-managed checks as stale';

    public function handle(): int
    {
        $statusService = app(PackageHealthStatusService::class);
        $notificationService = app(HealthEventNotificationService::class);

        Website::query()
            ->where('source', 'package')
            ->whereNotNull('package_interval')
            ->get()
            ->each(function (Website $website) use ($notificationService, $statusService): void {
                if (! $statusService->isStale($website->last_heartbeat_at, $website->package_interval)) {
                    return;
                }

                if ($website->stale_at !== null) {
                    return;
                }

                $summary = $statusService->staleSummary($website->package_interval);

                $website->forceFill([
                    'current_status' => 'danger',
                    'stale_at' => now(),
                    'status_summary' => $summary,
                ])->save();

                WebsiteLogHistory::create([
                    'website_id' => $website->id,
                    'status' => 'danger',
                    'summary' => $summary,
                ]);

                $notificationService->notifyWebsite($website, 'stale', 'danger', $summary);
            });

        MonitorApis::query()
            ->where('source', 'package')
            ->where('is_enabled', true)
            ->whereNotNull('package_interval')
            ->get()
            ->each(function (MonitorApis $monitorApi) use ($notificationService, $statusService): void {
                if (! $statusService->isStale($monitorApi->last_heartbeat_at, $monitorApi->package_interval)) {
                    return;
                }

                if ($monitorApi->stale_at !== null) {
                    return;
                }

                $summary = $statusService->staleSummary($monitorApi->package_interval);

                $monitorApi->forceFill([
                    'current_status' => 'danger',
                    'stale_at' => now(),
                    'status_summary' => $summary,
                ])->save();

                MonitorApiResult::create([
                    'monitor_api_id' => $monitorApi->id,
                    'is_success' => false,
                    'response_time_ms' => 0,
                    'http_code' => 0,
                    'status' => 'danger',
                    'summary' => $summary,
                ]);

                $notificationService->notifyApi($monitorApi, 'stale', 'danger', $summary);
            });

        return self::SUCCESS;
    }
}
