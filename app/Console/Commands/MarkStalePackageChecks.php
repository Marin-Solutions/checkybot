<?php

namespace App\Console\Commands;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\HealthEventNotificationService;
use App\Services\PackageHealthStatusService;
use App\Support\PackageIntervalDueExpression;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MarkStalePackageChecks extends Command
{
    private const CHUNK_SIZE = 500;

    protected $signature = 'app:mark-stale-package-checks';

    protected $description = 'Mark overdue package-managed checks as stale';

    public function handle(): int
    {
        $statusService = app(PackageHealthStatusService::class);
        $notificationService = app(HealthEventNotificationService::class);

        $this->overduePackageWebsitesQuery()
            ->chunkById(self::CHUNK_SIZE, function ($websites) use ($notificationService, $statusService): void {
                $websites->each(function (Website $website) use ($notificationService, $statusService): void {
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
            });

        $this->overduePackageApisQuery()
            ->chunkById(self::CHUNK_SIZE, function ($monitorApis) use ($notificationService, $statusService): void {
                $monitorApis->each(function (MonitorApis $monitorApi) use ($notificationService, $statusService): void {
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
            });

        return self::SUCCESS;
    }

    private function overduePackageWebsitesQuery(): Builder
    {
        return Website::query()
            ->where('source', 'package')
            ->whereNull('stale_at')
            ->whereNotNull('last_heartbeat_at')
            ->whereNotNull('package_interval')
            ->where('package_interval', '!=', '')
            ->where(function (Builder $query): void {
                $this->wherePackageIntervalIsOverdue($query);
            });
    }

    private function overduePackageApisQuery(): Builder
    {
        return MonitorApis::query()
            ->where('source', 'package')
            ->where('is_enabled', true)
            ->whereNull('stale_at')
            ->whereNotNull('last_heartbeat_at')
            ->whereNotNull('package_interval')
            ->where('package_interval', '!=', '')
            ->where(function (Builder $query): void {
                $this->wherePackageIntervalIsOverdue($query);
            });
    }

    private function wherePackageIntervalIsOverdue(Builder $query): void
    {
        [$intervalDueSql, $bindings] = PackageIntervalDueExpression::build($query->getConnection(), '<');

        $query->whereRaw($intervalDueSql, $bindings);
    }
}
