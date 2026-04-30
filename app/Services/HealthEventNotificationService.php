<?php

namespace App\Services;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Mail\HealthStatusAlert;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use App\Models\Website;
use App\Traits\ChecksWebhookResponses;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class HealthEventNotificationService
{
    use ChecksWebhookResponses;

    /**
     * Deliver a health-event alert across the website's configured channels.
     *
     * Returns true when the alert was either intentionally suppressed (the
     * monitor is currently snoozed), required no work (no channels), or
     * reached at least one channel — i.e. callers do not need to retry.
     * Returns false only when every attempted channel failed; callers that
     * support retry semantics use this to schedule another attempt.
     */
    public function notifyWebsite(Website $website, string $event, string $status, string $summary): bool
    {
        if ($this->isSilencedNow($website)) {
            Log::info('Skipping website health notification while monitor is snoozed', [
                'website_id' => $website->id,
                'silenced_until' => optional($website->silenced_until)->toIso8601String(),
                'event' => $event,
                'status' => $status,
            ]);

            return true;
        }

        $settings = NotificationSetting::query()
            ->active()
            ->where(function ($query) use ($website): void {
                $query->where(function ($inner) use ($website): void {
                    $inner->websiteScope()
                        ->where('website_id', $website->id);
                })->orWhere(function ($inner) use ($website): void {
                    $inner->globalScope()
                        ->where('user_id', $website->created_by);
                });
            })
            ->whereIn('inspection', [
                WebsiteServicesEnum::WEBSITE_CHECK->value,
                WebsiteServicesEnum::ALL_CHECK->value,
            ])
            ->get();

        return $this->deliver(
            $settings,
            name: $website->name,
            event: $event,
            status: $status,
            summary: $summary,
            url: $website->url,
        );
    }

    /**
     * @see notifyWebsite() for the boolean return-value contract.
     */
    public function notifyApi(MonitorApis $monitorApi, string $event, string $status, string $summary): bool
    {
        if ($this->isSilencedNow($monitorApi)) {
            Log::info('Skipping API monitor health notification while monitor is snoozed', [
                'monitor_api_id' => $monitorApi->id,
                'silenced_until' => optional($monitorApi->silenced_until)->toIso8601String(),
                'event' => $event,
                'status' => $status,
            ]);

            return true;
        }

        $settings = NotificationSetting::query()
            ->active()
            ->globalScope()
            ->where('user_id', $monitorApi->created_by)
            ->whereIn('inspection', [
                WebsiteServicesEnum::API_MONITOR->value,
                WebsiteServicesEnum::ALL_CHECK->value,
            ])
            ->get();

        return $this->deliver(
            $settings,
            name: $monitorApi->title,
            event: $event,
            status: $status,
            summary: $summary,
            url: $monitorApi->url,
        );
    }

    /**
     * Apply the API monitor transition alert rules used by scheduled and
     * control-triggered runs.
     *
     * @see notifyWebsite() for the boolean return-value contract.
     */
    public function notifyApiIfTransitioned(MonitorApis $monitorApi, ?string $previousStatus, string $status, string $summary): bool
    {
        if (
            in_array($status, ['warning', 'danger'], true)
            && $previousStatus !== $status
        ) {
            return $this->notifyApi($monitorApi, 'heartbeat', $status, $summary);
        }

        if (
            $status === 'healthy'
            && in_array($previousStatus, ['warning', 'danger'], true)
        ) {
            return $this->notifyApi($monitorApi, 'recovered', $status, $summary);
        }

        return true;
    }

    /**
     * Deliver across every configured channel, isolating per-channel failures.
     *
     * A bad transport on one channel (mail bounce, webhook timeout) used to
     * abort the whole delivery and leave the remaining channels unattempted.
     * Each channel is now wrapped in its own try/catch: failures are logged
     * with the setting id and we keep going. The return value tells the
     * caller whether retrying makes sense — true when nothing was attempted
     * or at least one channel got through, false only when every attempted
     * channel failed (treat that as a signal to retry later).
     */
    private function deliver(EloquentCollection $settings, string $name, string $event, string $status, string $summary, string $url): bool
    {
        $eventLabel = $this->eventLabel($event, $status);
        $message = $this->webhookMessage($name, $event, $status);

        $attempts = 0;
        $failures = 0;

        $settings->each(function (NotificationSetting $setting) use (
            $event,
            $eventLabel,
            $message,
            $name,
            $status,
            $summary,
            $url,
            &$attempts,
            &$failures,
        ): void {
            if ($setting->channel_type === NotificationChannelTypesEnum::MAIL) {
                $attempts++;

                try {
                    Mail::to($setting->address)->send(new HealthStatusAlert(
                        name: $name,
                        event: $event,
                        eventLabel: $eventLabel,
                        status: $status,
                        summary: $summary,
                        url: $url,
                    ));
                } catch (Throwable $exception) {
                    $failures++;
                    Log::error('Failed to deliver health notification mail; continuing with other channels', [
                        'setting_id' => $setting->id,
                        'event' => $event,
                        'status' => $status,
                        'exception' => $exception->getMessage(),
                    ]);
                }

                return;
            }

            if ($setting->channel_type === NotificationChannelTypesEnum::WEBHOOK) {
                $channel = $setting->channel;

                if (! $channel) {
                    Log::warning('No channel found for health notification setting', [
                        'setting_id' => $setting->id,
                    ]);

                    return;
                }

                $attempts++;

                try {
                    $response = $channel->sendWebhookNotification([
                        'message' => $message,
                        'description' => $summary,
                    ]);

                    if (! $this->webhookResponseWasSuccessful($response)) {
                        $failures++;

                        Log::error('Failed to deliver health notification webhook; continuing with other channels', [
                            'setting_id' => $setting->id,
                            'event' => $event,
                            'status' => $status,
                            'response_code' => (int) ($response['code'] ?? 0),
                            'response_body' => $response['body'] ?? null,
                        ]);
                    }
                } catch (Throwable $exception) {
                    $failures++;
                    Log::error('Failed to deliver health notification webhook; continuing with other channels', [
                        'setting_id' => $setting->id,
                        'event' => $event,
                        'status' => $status,
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        });

        // No transports were attempted (no settings, or only orphaned webhook
        // rows). Nothing to retry — the caller should treat this as success.
        if ($attempts === 0) {
            return true;
        }

        // Partial success counts as success: callers MUST NOT retry, otherwise
        // every successful channel would receive a duplicate alert each minute
        // until the failing transport recovers.
        return $failures < $attempts;
    }

    /**
     * Decide whether the monitor is currently silenced, consulting the
     * authoritative state at decision time.
     *
     * Concurrency hazard: callers like CheckApiMonitors::handle and
     * LogUptimeSslJob load the model at the start of a check cycle and only
     * call notify after the check finishes. In that window an operator can
     * snooze (or unsnooze) the monitor — in either direction the in-memory
     * `silenced_until` is stale. Trusting it would page the on-call through
     * their maintenance window, or — symmetrically — would silently swallow
     * the post-unsnooze alert because we still saw the old future timestamp.
     *
     * The contract here:
     *
     * - If the caller has a dirty in-memory mutation, respect it. This
     *   covers test fixtures that set `silenced_until` without persisting,
     *   and any code that legitimately overrides the value before notify.
     * - Otherwise re-read the persisted value with a targeted PK lookup so
     *   the latest concurrent change wins.
     */
    private function isSilencedNow(Website|MonitorApis $monitor): bool
    {
        if ($monitor->isDirty('silenced_until')) {
            return $monitor->isSilenced();
        }

        $persistedSilencedUntil = $monitor::query()
            ->whereKey($monitor->getKey())
            ->value('silenced_until');

        if ($persistedSilencedUntil === null) {
            return false;
        }

        // The query builder's value() returns the raw column value (string for
        // a timestamp). Carbon::parse handles both strings and DateTime
        // instances, so a single call covers any future shape change without
        // a dead defensive branch.
        return \Illuminate\Support\Carbon::parse($persistedSilencedUntil)->isFuture();
    }

    private function eventLabel(string $event, string $status): string
    {
        return $event === 'recovered' ? 'recovered' : $status;
    }

    private function webhookMessage(string $name, string $event, string $status): string
    {
        $label = $this->eventLabel($event, $status);

        return "[{$label}] {$name} {$event}";
    }
}
