<?php

namespace App\Console\Commands;

use App\Models\MonitorApiResult;
use App\Models\MonitorApis;
use App\Services\ApiMonitorExecutionService;
use App\Services\HealthEventNotificationService;
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
        $executionService = app(ApiMonitorExecutionService::class);
        $notificationService = app(HealthEventNotificationService::class);

        MonitorApis::query()
            ->where('is_enabled', true)
            ->chunkById(100, function ($monitors) use (&$count, $executionService, $notificationService): void {
                foreach ($monitors as $monitor) {
                    try {
                        $execution = $executionService->execute($monitor);
                        /** @var MonitorApiResult $result */
                        $result = $execution['result'];
                        $status = $execution['status'];
                        $summary = $execution['summary'];
                        $previousStatus = $execution['previous_status'];

                        if (! isset($result->http_code)) {
                            Sentry::configureScope(function (\Sentry\State\Scope $scope) use ($monitor, $execution): void {
                                $scope->setContext('monitor', [
                                    'monitor_id' => $monitor->id,
                                    'monitor_title' => $monitor->title,
                                    'url' => $monitor->url,
                                    'data_path' => $monitor->data_path,
                                    'result' => $execution,
                                ]);
                            });
                            throw new \Exception('Invalid API test result format - missing code');
                        }

                        $count++;

                        if (
                            in_array($status, ['warning', 'danger'], true)
                            && $previousStatus !== $status
                        ) {
                            $notificationService->notifyApi($monitor, 'heartbeat', $status, $summary);
                        } elseif (
                            $status === 'healthy'
                            && in_array($previousStatus, ['warning', 'danger'], true)
                        ) {
                            $notificationService->notifyApi($monitor, 'recovered', $status, $summary);
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
