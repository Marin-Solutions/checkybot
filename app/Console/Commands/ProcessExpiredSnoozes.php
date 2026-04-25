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
 * - The full row is re-read immediately before delivery and every alert
 *   precondition (status, active-monitoring guard, existence) is re-
 *   evaluated. This catches concurrent uptime-check recoveries AND
 *   concurrent toggle-offs (operator pausing the monitor between our
 *   fresh fetch and the notify call), both of which would otherwise
 *   produce a stale page.
 * - Disabled monitors are skipped: API monitors with `is_enabled = false`,
 *   and websites whose `current_status` is no longer maintained by any
 *   pipeline. Status is updated by `LogUptimeSslJob` when `uptime_check`
 *   is on, and by `MarkStalePackageChecks` for `source = 'package'` rows
 *   with a `package_interval` (this matters because package SSL checks
 *   are created with `uptime_check = false` but still receive status
 *   updates). Standard websites with only SSL/outbound on don't qualify —
 *   their stored status is frozen from before the toggle change. The
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
                    $delivered = $this->deliverIfStillAlertable(
                        Website::class,
                        $fresh->id,
                        fn (Website $latest): bool => $this->isWebsiteActivelyMonitored($latest),
                        fn (Website $latest) => $notificationService->notifyWebsite(
                            $latest,
                            'snooze_expired',
                            $latest->current_status,
                            $this->summaryFor($latest->status_summary, $latest->current_status),
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
                    $delivered = $this->deliverIfStillAlertable(
                        MonitorApis::class,
                        $fresh->id,
                        fn (MonitorApis $latest): bool => (bool) $latest->is_enabled,
                        fn (MonitorApis $latest) => $notificationService->notifyApi(
                            $latest,
                            'snooze_expired',
                            $latest->current_status,
                            $this->summaryFor($latest->status_summary, $latest->current_status),
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
     * Re-read the full row right before delivery and re-evaluate every
     * condition the alert depends on — current status, the active-
     * monitoring guard (uptime_check / is_enabled / package fallbacks),
     * and implicitly that the row still exists. This is the authoritative
     * decision point: it catches concurrent recoveries (status flipped to
     * healthy) AND concurrent toggles (operator paused the monitor while
     * we were preparing to alert), both of which can land in the gap
     * between fresh-fetch and notify call. Returns:
     *
     * - true  → delivered (or partially delivered; no retry needed)
     * - false → delivery failed entirely; caller must preserve silenced_until
     * - null  → not alertable on the latest read (recovered, paused, or
     *           gone); caller proceeds to clear silenced_until
     *
     * @param  class-string<Website|MonitorApis>  $modelClass
     * @param  array<string, mixed>  $logContext
     */
    private function deliverIfStillAlertable(string $modelClass, int $id, \Closure $isStillActive, \Closure $delivery, array $logContext): ?bool
    {
        $latest = $modelClass::find($id);

        if ($latest === null) {
            return null;
        }

        if (! $isStillActive($latest)) {
            return null;
        }

        if (! in_array($latest->current_status, ['warning', 'danger'], true)) {
            return null;
        }

        return $this->safelyDeliver(
            fn () => $delivery($latest),
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
     * `current_status` for a website is written by two pipelines:
     *
     * 1. `LogUptimeSslJob` — gated on `uptime_check`; short-circuits at
     *    line 39 when the toggle is off.
     * 2. `MarkStalePackageChecks` — runs for every `source = 'package'`
     *    row that has a `package_interval`, regardless of `uptime_check`.
     *    Package-managed SSL checks are created with `uptime_check = false`
     *    by `CheckSyncService::syncSslChecks()`, but they still get their
     *    `current_status` flipped to `danger` when stale.
     *
     * A monitor is "actively monitored" — i.e. its stored status is fresh
     * enough to alert on — when *any* of those two pipelines applies. SSL-
     * cert and outbound-link checks on standard websites do not write to
     * `current_status` and therefore do not qualify.
     */
    private function isWebsiteActivelyMonitored(Website $website): bool
    {
        if ($website->uptime_check) {
            return true;
        }

        return $website->source === 'package' && $website->package_interval !== null;
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
