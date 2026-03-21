<?php

namespace App\Services;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Mail\ProjectComponentAlertMail;
use App\Models\NotificationSetting;
use App\Models\ProjectComponent;
use Illuminate\Support\Facades\Mail;

class ProjectComponentNotificationService
{
    public function notify(ProjectComponent $component, string $event, string $status): void
    {
        if (! in_array($status, ['warning', 'danger'], true) && $event !== 'stale') {
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
                $setting->channel?->sendWebhookNotification([
                    'message' => $payload['message'],
                    'description' => $payload['details'],
                ]);

                continue;
            }

            Mail::to($setting->address)->send(new ProjectComponentAlertMail($payload));
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildPayload(ProjectComponent $component, string $event, string $status): array
    {
        $eventLabel = $event === 'stale' ? 'stale' : $status;

        return [
            'subject' => "Application component {$eventLabel}: {$component->name}",
            'title' => "Application component {$eventLabel}",
            'message' => "{$component->project->name} / {$component->name} reported {$eventLabel}.",
            'details' => $component->summary ?? 'No additional summary was provided.',
        ];
    }
}
