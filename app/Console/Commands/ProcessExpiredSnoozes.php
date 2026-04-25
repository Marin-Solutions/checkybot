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
 * - Status is re-validated immediately before delivery. A concurrent
 *   uptime check can flip `current_status` to healthy in the millisecond
 *   window between our fresh fetch and the notify call; without this
 *   final probe the operator would receive a stale "still unhealthy"
 *   page about a monitor that has already recovered.
 * - Disabled API monitors and websites with `uptime_check` off are
 *   skipped — `current_status` is only updated by the uptime pipeline
 *   (LogUptimeSslJob short-circuits when uptime_check is false), so any
 *   stored danger value is frozen from before the toggle change. SSL and
 *   outbound checks don't keep `current_status` fresh on their own. The
 *   snooze marker is still cleared so the UI doesn't keep showing an
 *   expired window.
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

                if ($this->shouldAlert($fresh->current_status, $this->isWebsiteActivelyMonitored($fresh))) {
                    $delivered = $this->deliverIfStillUnhealthy(
                        Website::class,
                        $fresh->id,
                        fn (string $status, ?string $summary) => $notificationService->notifyWebsite(
                            $fresh,
                            'snooze_expired',
                            $status,
                            $this->summaryFor($summary, $status),
                        ),
                        ['website_id' => $fresh->id],
                    );

                    if ($delivered === false) {
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

                if ($this->shouldAlert($fresh->current_status, (bool) $fresh->is_enabled)) {
                    $delivered = $this->deliverIfStillUnhealthy(
                        MonitorApis::class,
                        $fresh->id,
                        fn (string $status, ?string $summary) => $notificationService->notifyApi(
                            $fresh,
                            'snooze_expired',
                            $status,
                            $this->summaryFor($summary, $status),
                        ),
                        ['monitor_api_id' => $fresh->id],
                    );

                    if ($delivered === false) {
                        return;
                    }
                }

                $this->clearIfStillExpired(MonitorApis::class, $fresh->id);
            });
    }

    /**
     * Re-read current_status + status_summary right before delivery, then
     * dispatch only if the monitor is still unhealthy. Returns:
     *
     * - true  → delivered (or partially delivered; no retry needed)
     * - false → delivery failed entirely; caller must preserve silenced_until
     * - null  → recovered concurrently; skip alert but proceed to clear
     *
     * @param  class-string<Website|MonitorApis>  $modelClass
     * @param  array<string, mixed>  $logContext
     */
    private function deliverIfStillUnhealthy(string $modelClass, int $id, \Closure $delivery, array $logContext): ?bool
    {
        $latest = $modelClass::query()
            ->whereKey($id)
            ->select(['current_status', 'status_summary'])
            ->first();

        if (! in_array($latest?->current_status, ['warning', 'danger'], true)) {
            return null;
        }

        return $this->safelyDeliver(
            fn () => $delivery($latest->current_status, $latest->status_summary),
            $logContext,
        );
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

    /**
     * `current_status` is updated only by `LogUptimeSslJob`, which short-
     * circuits when `uptime_check` is false. SSL-cert and outbound-link
     * checks live on their own pipelines and do not refresh the uptime-
     * derived status, so the value is frozen the moment uptime is paused.
     * Re-firing a snooze-expired alert from that frozen status would
     * surface a stale outage for an intentionally idle pipeline — gate
     * this strictly on the uptime toggle.
     */
    private function isWebsiteActivelyMonitored(Website $website): bool
    {
        return (bool) $website->uptime_check;
    }

    /**
     * Run the delivery callback and return whether it completed successfully.
     *
     * Two failure modes leave `silenced_until` intact for the next run:
     *   - the closure throws (catastrophic / non-channel exception)
     *   - the closure returns `false`, which the notification service uses
     *     to signal that every attempted channel failed
     *
     * Partial channel failures inside the service do *not* return false —
     * they are logged but considered successful here so we don't re-fire
     * to channels that already received the alert.
     *
     * @param  array<string, mixed>  $logContext
     */
    private function safelyDeliver(\Closure $deliver, array $logContext): bool
    {
        try {
            $delivered = $deliver();
        } catch (Throwable $exception) {
            Log::error('Failed to deliver snooze-expired alert; retaining silenced_until for retry', [
                ...$logContext,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($delivered === false) {
            Log::warning('Snooze-expired alert had no successful channel deliveries; retaining silenced_until for retry', $logContext);

            return false;
        }

        return true;
    }

    private function summaryFor(?string $existingSummary, ?string $status): string
    {
        $base = trim((string) $existingSummary);
        $stateLabel = $status ?: 'unhealthy';

        $context = "Snooze window ended; monitor is still {$stateLabel}.";

        return $base === '' ? $context : "{$context} {$base}";
    }
}
