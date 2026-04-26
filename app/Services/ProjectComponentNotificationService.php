<?php

namespace App\Services;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Mail\ProjectComponentAlertMail;
use App\Models\NotificationSetting;
use App\Models\ProjectComponent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ProjectComponentNotificationService
{
    public function notify(ProjectComponent $component, string $event, string $status): void
    {
        if (
            ! in_array($status, ['warning', 'danger'], true)
            && ! in_array($event, ['stale', 'recovered'], true)
        ) {
            return;
        }

        $settings = NotificationSetting::query()
            ->where('user_id', $component->project->created_by)
            ->active()
            ->whereIn('inspection', [
                WebsiteServicesEnum::APPLICATION_HEALTH->value,
                WebsiteServicesEnum::ALL_CHECK->value,
            ])
            ->with('channel')
            ->get();

        $payload = $this->buildPayload($component, $event, $status);

        foreach ($settings as $setting) {
            if ($setting->channel_type === NotificationChannelTypesEnum::WEBHOOK) {
                $channel = $setting->channel;

                if (! $channel) {
                    Log::warning('No channel found for project component notification setting', [
                        'setting_id' => $setting->id,
                        'project_component_id' => $component->id,
                        'event' => $event,
                        'status' => $status,
                    ]);

                    continue;
                }

                try {
                    $channel->sendWebhookNotification([
                        'message' => $payload['message'],
                        'description' => $payload['details'],
                    ]);
                } catch (Throwable $exception) {
                    Log::error('Failed to deliver project component notification webhook; continuing with other channels', [
                        'setting_id' => $setting->id,
                        'project_component_id' => $component->id,
                        'event' => $event,
                        'status' => $status,
                        'exception' => $exception,
                    ]);
                }

                continue;
            }

            if ($setting->channel_type !== NotificationChannelTypesEnum::MAIL) {
                continue;
            }

            try {
                Mail::to($setting->address)->send(new ProjectComponentAlertMail($payload));
            } catch (Throwable $exception) {
                Log::error('Failed to deliver project component notification mail; continuing with other channels', [
                    'setting_id' => $setting->id,
                    'project_component_id' => $component->id,
                    'event' => $event,
                    'status' => $status,
                    'exception' => $exception,
                ]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildPayload(ProjectComponent $component, string $event, string $status): array
    {
        $eventLabel = match ($event) {
            'stale' => 'stale',
            'recovered' => 'recovered',
            default => $status,
        };

        return [
            'subject' => "Application component {$eventLabel}: {$component->name}",
            'title' => "Application component {$eventLabel}",
            'message' => "{$component->project->name} / {$component->name} reported {$eventLabel}.",
            'details' => $component->summary ?? 'No additional summary was provided.',
        ];
    }
}
