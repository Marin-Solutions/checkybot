<?php

namespace App\Console\Commands;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Services\HealthEventNotificationService;
use App\Services\PackageHealthStatusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Sentry\Laravel\Facade as Sentry;

class CheckApiMonitors extends Command
{
    protected $signature = 'monitor:check-apis';

    protected $description = 'Check all API monitors and record their results';

    public function handle(): int
    {
        $this->info('Starting API monitor checks...');
        $count = 0;
        $statusService = app(PackageHealthStatusService::class);
        $notificationService = app(HealthEventNotificationService::class);

        MonitorApis::query()->chunkById(100, function ($monitors) use (&$count, $statusService, $notificationService): void {
            foreach ($monitors as $monitor) {
                try {
                    $startTime = microtime(true);
                    $result = MonitorApis::testApi([
                        'id' => $monitor->id,
                        'url' => $monitor->url,
                        'data_path' => $monitor->data_path,
                        'headers' => $monitor->headers,
                        'title' => $monitor->title,
                    ]);

                    if (! isset($result['code'])) {
                        Sentry::configureScope(function (\Sentry\State\Scope $scope) use ($monitor, $result): void {
                            $scope->setContext('monitor', [
                                'monitor_id' => $monitor->id,
                                'monitor_title' => $monitor->title,
                                'url' => $monitor->url,
                                'data_path' => $monitor->data_path,
                                'result' => $result,
                            ]);
                        });
                        throw new \Exception('Invalid API test result format - missing code');
                    }

                    $status = $statusService->apiStatusFromResult($result);
                    $summary = $statusService->summaryForApi($result);
                    $previousStatus = $monitor->current_status;

                    MonitorApiResult::recordResult($monitor, $result, $startTime, $status, $summary);

                    $monitor->forceFill([
                        'current_status' => $status,
                        'last_heartbeat_at' => now(),
                        'stale_at' => null,
                        'status_summary' => $summary,
                    ])->save();

                    $count++;

                    if (
                        $monitor->source === 'package'
                        && in_array($status, ['warning', 'danger'], true)
                        && $previousStatus !== $status
                    ) {
                        $notificationService->notifyApi($monitor, 'heartbeat', $status, $summary);
                    }
                } catch (\Exception $e) {
                    Log::error('Error checking API monitor: '.$e->getMessage(), [
                        'monitor_id' => $monitor->id,
                        'monitor_title' => $monitor->title,
                    ]);
                    Sentry::captureException($e);
                }
            }
        });

        $this->info("Completed checking {$count} API monitors.");

        return Command::SUCCESS;
    }
}
