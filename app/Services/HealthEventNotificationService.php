<?php

namespace App\Services;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Mail\HealthStatusAlert;
use App\Models\MonitorApis;
use App\Models\NotificationSetting;
use App\Models\Website;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class HealthEventNotificationService
{
    public function notifyWebsite(Website $website, string $event, string $status, string $summary): void
    {
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

        $this->deliver(
            $settings,
            name: $website->name,
            event: $event,
            status: $status,
            summary: $summary,
            url: $website->url,
        );
    }

    public function notifyApi(MonitorApis $monitorApi, string $event, string $status, string $summary): void
    {
        $settings = NotificationSetting::query()
            ->active()
            ->globalScope()
            ->where('user_id', $monitorApi->created_by)
            ->whereIn('inspection', [
                WebsiteServicesEnum::API_MONITOR->value,
                WebsiteServicesEnum::ALL_CHECK->value,
            ])
            ->get();

        $this->deliver(
            $settings,
            name: $monitorApi->title,
            event: $event,
            status: $status,
            summary: $summary,
            url: $monitorApi->url,
        );
    }

    private function deliver(EloquentCollection $settings, string $name, string $event, string $status, string $summary, string $url): void
    {
        $eventLabel = $this->eventLabel($event, $status);
        $message = $this->webhookMessage($name, $event, $status);

        $settings->each(function (NotificationSetting $setting) use ($event, $eventLabel, $message, $name, $status, $summary, $url): void {

            if ($setting->channel_type === NotificationChannelTypesEnum::MAIL) {
                Mail::to($setting->address)->send(new HealthStatusAlert(
                    name: $name,
                    event: $event,
                    eventLabel: $eventLabel,
                    status: $status,
                    summary: $summary,
                    url: $url,
                ));

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

                $channel->sendWebhookNotification([
                    'message' => $message,
                    'description' => $summary,
                ]);
            }
        });
    }

    private function eventLabel(string $event, string $status): string
    {
        return $event === 'recovered' ? 'recovered' : $status;
    }

    private function webhookMessage(string $name, string $event, string $status): string
    {
        $label = $event === 'recovered' ? 'recovered' : $status;

        return "[{$label}] {$name} {$event}";
    }
}
