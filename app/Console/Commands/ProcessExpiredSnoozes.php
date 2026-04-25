<?php

namespace App\Console\Commands;

use App\Models\MonitorApis;
use App\Models\Website;
use App\Services\HealthEventNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * `silenced_until` has just passed:
 *
 * - We attempt to deliver the suppressed alert *first*, only clearing the
 *   timestamp once delivery succeeds. A transport failure (mail bounce,
 *   webhook timeout) leaves `silenced_until` set so the next run retries
 *   instead of silently losing the alert.
 * - Disabled API monitors and websites with every check toggled off are
 *   skipped — their `current_status` is frozen and re-firing a stale alert
 *   would mislead the operator. The snooze marker is still cleared so the
 *   UI doesn't keep showing an expired window.
 * - Healthy monitors get their snooze cleared with no alert.
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

                if ($this->shouldAlert($status, $this->isWebsiteActivelyMonitored($website))) {
                    $delivered = $this->safelyDeliver(
                        fn () => $notificationService->notifyWebsite(
                            $website,
                            'snooze_expired',
                            $status,
                            $this->summaryFor($website->status_summary, $status),
                        ),
                        ['website_id' => $website->id],
                    );

                    if (! $delivered) {
                        return;
                    }
                }

                $website->update(['silenced_until' => null]);
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

                if ($this->shouldAlert($status, (bool) $monitorApi->is_enabled)) {
                    $delivered = $this->safelyDeliver(
                        fn () => $notificationService->notifyApi(
                            $monitorApi,
                            'snooze_expired',
                            $status,
                            $this->summaryFor($monitorApi->status_summary, $status),
                        ),
                        ['monitor_api_id' => $monitorApi->id],
                    );

                    if (! $delivered) {
                        return;
                    }
                }

                $monitorApi->update(['silenced_until' => null]);
            });
    }

    private function shouldAlert(?string $status, bool $isActivelyMonitored): bool
    {
        return $isActivelyMonitored && in_array($status, ['warning', 'danger'], true);
    }

    private function isWebsiteActivelyMonitored(Website $website): bool
    {
        return (bool) ($website->uptime_check || $website->ssl_check || $website->outbound_check);
    }

    /**
     * Run the delivery callback and return whether it completed successfully.
     *
     * On failure we leave `silenced_until` intact so the next reconciliation
     * retries — preferable to silently dropping an alert when a transport is
     * temporarily down.
     *
     * @param  array<string, mixed>  $logContext
     */
    private function safelyDeliver(\Closure $deliver, array $logContext): bool
    {
        try {
            $deliver();

            return true;
        } catch (Throwable $exception) {
            Log::error('Failed to deliver snooze-expired alert; retaining silenced_until for retry', [
                ...$logContext,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function summaryFor(?string $existingSummary, ?string $status): string
    {
        $base = trim((string) $existingSummary);
        $stateLabel = $status ?: 'unhealthy';

        $context = "Snooze window ended; monitor is still {$stateLabel}.";

        return $base === '' ? $context : "{$context} {$base}";
    }
}
