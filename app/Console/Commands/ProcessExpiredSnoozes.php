<?php

namespace App\Console\Commands;

use App\Models\MonitorApis;
use App\Models\Website;
use App\Services\HealthEventNotificationService;
use Illuminate\Console\Command;

/**
 * Reconciles monitors whose snooze window has just expired.
 *
 * The status-change check runners (`CheckApiMonitors`, `LogUptimeSslJob`)
 * only call the notification service on a status transition. While a monitor
 * is silenced, those transition alerts are dropped on purpose — that is the
 * point of snoozing. The hazard is the case where a monitor flips to
 * warning/danger *during* the snooze: the transition is dropped, and once
 * `silenced_until` passes the monitor is still in the same failed state, so
 * no further transition fires. Without this command an outage that outlasts
 * a maintenance window would remain unalerted indefinitely.
 *
 * The reconciliation pass runs every minute. For each monitor whose
 * `silenced_until` has just passed, it clears the timestamp (so subsequent
 * runs ignore the row) and, if the monitor is still unhealthy, re-fires the
 * suppressed alert via the notification service.
 */
class ProcessExpiredSnoozes extends Command
{
    protected $signature = 'app:process-expired-snoozes';

    protected $description = 'Clear expired snoozes and re-fire alerts for monitors that are still unhealthy.';

    public function handle(HealthEventNotificationService $notificationService): int
    {
        $this->processWebsites($notificationService);
        $this->processApiMonitors($notificationService);

        return self::SUCCESS;
    }

    private function processWebsites(HealthEventNotificationService $notificationService): void
    {
        Website::query()
            ->whereNotNull('silenced_until')
            ->where('silenced_until', '<=', now())
            ->get()
            ->each(function (Website $website) use ($notificationService): void {
                $status = $website->current_status;
                $summary = $this->summaryFor($website->status_summary, $status);

                $website->update(['silenced_until' => null]);

                if ($this->isUnhealthy($status)) {
                    $notificationService->notifyWebsite($website, 'snooze_expired', $status, $summary);
                }
            });
    }

    private function processApiMonitors(HealthEventNotificationService $notificationService): void
    {
        MonitorApis::query()
            ->whereNotNull('silenced_until')
            ->where('silenced_until', '<=', now())
            ->get()
            ->each(function (MonitorApis $monitorApi) use ($notificationService): void {
                $status = $monitorApi->current_status;
                $summary = $this->summaryFor($monitorApi->status_summary, $status);

                $monitorApi->update(['silenced_until' => null]);

                if ($this->isUnhealthy($status)) {
                    $notificationService->notifyApi($monitorApi, 'snooze_expired', $status, $summary);
                }
            });
    }

    private function isUnhealthy(?string $status): bool
    {
        return in_array($status, ['warning', 'danger'], true);
    }

    private function summaryFor(?string $existingSummary, ?string $status): string
    {
        $base = trim((string) $existingSummary);
        $stateLabel = $status ?: 'unhealthy';

        $context = "Snooze window ended; monitor is still {$stateLabel}.";

        return $base === '' ? $context : "{$context} {$base}";
    }
}
