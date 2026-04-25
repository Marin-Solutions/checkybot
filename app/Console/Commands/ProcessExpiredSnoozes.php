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
 * - Each row is re-fetched before processing and cleared via a conditional
 *   UPDATE that only fires when `silenced_until` is still in the past.
 *   Together these guard against a user re-snoozing the monitor between
 *   our SELECT snapshot and the per-row work — without this we would fire
 *   a stale alert and clobber the operator's fresh snooze with `null`.
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
                $fresh = $website->fresh();

                if (! $this->isStillExpired($fresh)) {
                    return;
                }

                $status = $fresh->current_status;

                if ($this->shouldAlert($status, $this->isWebsiteActivelyMonitored($fresh))) {
                    $delivered = $this->safelyDeliver(
                        fn () => $notificationService->notifyWebsite(
                            $fresh,
                            'snooze_expired',
                            $status,
                            $this->summaryFor($fresh->status_summary, $status),
                        ),
                        ['website_id' => $fresh->id],
                    );

                    if (! $delivered) {
                        return;
                    }
                }

                $this->clearIfStillExpired(Website::class, $fresh->id);
            });
    }

    private function processApiMonitors(HealthEventNotificationService $notificationService): void
    {
        MonitorApis::query()
            ->whereNotNull('silenced_until')
            ->where('silenced_until', '<=', now())
            ->get()
            ->each(function (MonitorApis $monitorApi) use ($notificationService): void {
                $fresh = $monitorApi->fresh();

                if (! $this->isStillExpired($fresh)) {
                    return;
                }

                $status = $fresh->current_status;

                if ($this->shouldAlert($status, (bool) $fresh->is_enabled)) {
                    $delivered = $this->safelyDeliver(
                        fn () => $notificationService->notifyApi(
                            $fresh,
                            'snooze_expired',
                            $status,
                            $this->summaryFor($fresh->status_summary, $status),
                        ),
                        ['monitor_api_id' => $fresh->id],
                    );

                    if (! $delivered) {
                        return;
                    }
                }

                $this->clearIfStillExpired(MonitorApis::class, $fresh->id);
            });
    }

    /**
     * True iff the row is still in the "snooze just expired" state — i.e.
     * `silenced_until` is non-null and not in the future. A user re-snoozing
     * between our outer SELECT and this re-fetch would push the timestamp
     * back into the future, in which case we bail out without delivering or
     * clearing.
     */
    private function isStillExpired(Website|MonitorApis|null $monitor): bool
    {
        if ($monitor === null || $monitor->silenced_until === null) {
            return false;
        }

        return ! $monitor->silenced_until->isFuture();
    }

    /**
     * Clear `silenced_until` only if the row is still expired in the database.
     *
     * The conditional `where('silenced_until', '<=', now())` is the atomic
     * defence against a re-snooze that lands between our delivery and clear:
     * if the operator pushed the timestamp back into the future, this UPDATE
     * matches zero rows and the fresh snooze is preserved.
     *
     * @param  class-string<Website|MonitorApis>  $modelClass
     */
    private function clearIfStillExpired(string $modelClass, int $id): void
    {
        $modelClass::query()
            ->whereKey($id)
            ->whereNotNull('silenced_until')
            ->where('silenced_until', '<=', now())
            ->update(['silenced_until' => null]);
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
