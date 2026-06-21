<?php

namespace App\Services;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Mail\ProjectComponentAlertMail;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use App\Models\ProjectComponent;
use App\Support\MetricsPayloadFormatter;
use App\Support\ProjectComponentDeliveryState;
use App\Traits\ChecksWebhookResponses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ProjectComponentNotificationService
{
    use ChecksWebhookResponses;

    public function notify(ProjectComponent $component, string $event, string $status): bool
    {
        if (
            ! in_array($status, ['warning', 'danger'], true)
            && ! in_array($event, ['stale', 'recovered'], true)
        ) {
            return true;
        }

        if ($this->isSilencedNow($component)) {
            Log::info('Skipping project component notification while component is snoozed', [
                'project_component_id' => $component->id,
                'silenced_until' => optional($component->silenced_until)->toIso8601String(),
                'event' => $event,
                'status' => $status,
            ]);

            return true;
        }

        $project = $component->project;

        if ($project === null) {
            Log::error('Skipping project component notification because the project relationship is missing', [
                'project_component_id' => $component->id,
                'project_id' => $component->project_id,
                'event' => $event,
                'status' => $status,
            ]);

            return false;
        }

        $settings = NotificationSetting::query()
            ->active()
            ->where(function ($query) use ($component, $project): void {
                $query->where(function ($inner) use ($component): void {
                    $inner->projectComponentScope()
                        ->where('project_component_id', $component->id);
                })->orWhere(function ($inner) use ($project): void {
                    $inner->projectScope()
                        ->where('project_id', $project->id);
                })->orWhere(function ($inner) use ($project): void {
                    $inner->globalScope()
                        ->where('user_id', $project->created_by);
                });
            })
            ->whereIn('inspection', [
                WebsiteServicesEnum::APPLICATION_HEALTH->value,
                WebsiteServicesEnum::ALL_CHECK->value,
            ])
            ->with('channel')
            ->get();

        $payload = $this->buildPayload($component, $event, $status);
        $attempts = 0;
        $failures = 0;

        foreach ($settings as $setting) {
            if ($setting->channel_type === NotificationChannelTypesEnum::WEBHOOK) {
                $channel = $setting->channel;

                if (! $channel) {
                    $setting->recordDeliveryAttempt(
                        kind: 'send',
                        succeeded: false,
                        responseCode: null,
                        summary: 'Webhook channel is missing.',
                    );

                    Log::warning('No channel found for project component notification setting', [
                        'setting_id' => $setting->id,
                        'project_component_id' => $component->id,
                        'event' => $event,
                        'status' => $status,
                    ]);

                    continue;
                }

                $attempts++;

                try {
                    $response = $channel->sendWebhookNotification([
                        'message' => $payload['message'],
                        'description' => $payload['details'],
                    ]);
                    $code = (int) ($response['code'] ?? 0);

                    $setting->recordDeliveryAttempt(
                        kind: 'send',
                        succeeded: $this->webhookResponseWasSuccessful($response),
                        responseCode: $code ?: null,
                        summary: NotificationChannels::summarizeDeliveryResponse($response),
                    );

                    if (! $this->webhookResponseWasSuccessful($response)) {
                        $failures++;

                        Log::error('Failed to deliver project component notification webhook; continuing with other channels', [
                            'setting_id' => $setting->id,
                            'project_component_id' => $component->id,
                            'event' => $event,
                            'status' => $status,
                            'response_code' => (int) ($response['code'] ?? 0),
                            'response_body' => $response['body'] ?? null,
                        ]);
                    }
                } catch (Throwable $exception) {
                    $failures++;

                    $setting->recordDeliveryAttempt(
                        kind: 'send',
                        succeeded: false,
                        responseCode: null,
                        summary: 'Unexpected webhook error: '.$exception->getMessage(),
                    );

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

            $attempts++;
            $mailException = null;

            try {
                Mail::to($setting->address)->send(new ProjectComponentAlertMail($payload));
            } catch (Throwable $exception) {
                $mailException = $exception;

                Log::error('Failed to deliver project component notification mail; continuing with other channels', [
                    'setting_id' => $setting->id,
                    'project_component_id' => $component->id,
                    'event' => $event,
                    'status' => $status,
                    'exception' => $exception,
                ]);
            }

            if ($mailException) {
                $failures++;

                $setting->recordDeliveryAttempt(
                    kind: 'send',
                    succeeded: false,
                    responseCode: null,
                    summary: 'Mail transport error: '.$mailException->getMessage(),
                );

                continue;
            }

            $setting->recordDeliveryAttempt(
                kind: 'send',
                succeeded: true,
                responseCode: null,
                summary: 'Email accepted by configured mail transport.',
            );
        }

        if ($attempts === 0) {
            return true;
        }

        return $failures < $attempts;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(ProjectComponent $component, string $event, string $status): array
    {
        $component->loadMissing('project');

        $eventLabel = match ($event) {
            'stale' => 'stale',
            'recovered' => 'recovered',
            default => $status,
        };

        $observedAt = $component->updated_at;
        $staleThresholdAt = $this->staleThresholdAt($component);
        $deliveryState = ProjectComponentDeliveryState::value($component);
        $deliveryStateLabel = ProjectComponentDeliveryState::label($component);
        $metrics = $component->metrics ?? [];
        $formattedMetrics = MetricsPayloadFormatter::format($metrics);
        $summary = $component->summary ?? 'No additional summary was provided.';
        $evidence = [
            ['label' => 'Observed at', 'value' => $this->formatDateTime($observedAt), 'type' => 'text'],
            ['label' => 'Interval', 'value' => $this->formatInterval($component), 'type' => 'text'],
            ['label' => 'Stale threshold', 'value' => $this->formatDateTime($staleThresholdAt), 'type' => 'text'],
            ['label' => 'Delivery state', 'value' => $deliveryStateLabel, 'type' => 'text'],
            ['label' => 'Metrics', 'value' => $formattedMetrics, 'type' => 'code'],
        ];

        return [
            'subject' => "Application component {$eventLabel}: {$component->name}",
            'title' => "Application component {$eventLabel}",
            'message' => "{$component->project->name} / {$component->name} reported {$eventLabel}.",
            'details' => $this->formatDetails($summary, $evidence),
            'summary' => $summary,
            'project_name' => $component->project->name,
            'component_name' => $component->name,
            'event' => $event,
            'event_label' => $eventLabel,
            'status' => $status,
            'observed_at' => $observedAt?->toIso8601String(),
            'observed_at_formatted' => $this->formatDateTime($observedAt),
            'interval' => $component->declared_interval,
            'interval_minutes' => $component->interval_minutes,
            'interval_formatted' => $this->formatInterval($component),
            'stale_threshold_at' => $staleThresholdAt?->toIso8601String(),
            'stale_threshold_at_formatted' => $this->formatDateTime($staleThresholdAt),
            'delivery_state' => $deliveryState,
            'delivery_state_label' => $deliveryStateLabel,
            'metrics' => $metrics,
            'formatted_metrics' => $formattedMetrics,
            'evidence' => $evidence,
        ];
    }

    private function staleThresholdAt(ProjectComponent $component): ?Carbon
    {
        if ($component->interval_minutes === null) {
            return null;
        }

        $anchorAt = $component->created_at;

        if ($anchorAt === null) {
            return null;
        }

        return $anchorAt->copy()->addMinutes(
            $component->interval_minutes + $this->staleGraceMinutes()
        );
    }

    private function staleGraceMinutes(): int
    {
        return max(0, (int) config('monitor.project_component_stale_grace_minutes'));
    }

    private function formatDateTime(?Carbon $dateTime): string
    {
        return $dateTime?->toIso8601String() ?? 'Not recorded';
    }

    private function formatInterval(ProjectComponent $component): string
    {
        if ($component->declared_interval !== null && $component->declared_interval !== '') {
            if ($component->interval_minutes !== null) {
                return "{$component->declared_interval} ({$component->interval_minutes} min)";
            }

            return $component->declared_interval;
        }

        if ($component->interval_minutes !== null) {
            return IntervalParser::fromMinutes($component->interval_minutes);
        }

        return 'Not configured';
    }

    /**
     * @param  array<int, array{label: string, value: string, type: string}>  $evidence
     */
    private function formatDetails(string $summary, array $evidence): string
    {
        $lines = [$summary, '', 'Evidence:'];

        foreach ($evidence as $item) {
            $lines[] = "{$item['label']}: {$item['value']}";
        }

        return implode("\n", $lines);
    }

    private function isSilencedNow(ProjectComponent $component): bool
    {
        if ($component->isDirty('silenced_until')) {
            return $component->isSilenced();
        }

        $persistedSilencedUntil = ProjectComponent::query()
            ->whereKey($component->getKey())
            ->value('silenced_until');

        if ($persistedSilencedUntil === null) {
            return false;
        }

        return Carbon::parse($persistedSilencedUntil)->isFuture();
    }
}
