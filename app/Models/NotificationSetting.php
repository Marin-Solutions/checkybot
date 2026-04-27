<?php

namespace App\Models;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Mail\EmailReminderSsl;
use App\Mail\HealthStatusAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class NotificationSetting extends Model
{
    use HasFactory;

    protected $table = 'notification_settings';

    protected $fillable = [
        'user_id',
        'website_id',
        'scope',
        'inspection',
        'channel_type',
        'notification_channel_id',
        'address',
        'data_path',
        'flag_active',
    ];

    protected function casts(): array
    {
        return [
            'scope' => NotificationScopesEnum::class,
            'inspection' => WebsiteServicesEnum::class,
            'channel_type' => NotificationChannelTypesEnum::class,
            'flag_active' => 'boolean',
        ];
    }

    public function getChannelTypeValueAttribute($value): string
    {
        return NotificationChannelTypesEnum::from($this->attributes['channel_type'])->label();
    }

    public function getScopeValueAttribute($value): string
    {
        return NotificationScopesEnum::from($this->attributes['scope'])->label();
    }

    public function getInspectionValueAttribute($value): string
    {
        return WebsiteServicesEnum::from($this->attributes['inspection'])->label();
    }

    public function website(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeGlobalScope(Builder $query): void
    {
        $query->where('scope', NotificationScopesEnum::GLOBAL->name);
    }

    public function scopeWebsiteScope(Builder $query): void
    {
        $query->where('scope', NotificationScopesEnum::WEBSITE->name);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('flag_active', 1);
    }

    public function sendSslNotification(?string $message = null, array $data = []): bool
    {
        $channelType = $this->resolveChannelType();

        switch ($channelType) {
            case NotificationChannelTypesEnum::MAIL:
                return $this->sendEmail($data, EmailReminderSsl::class);

            case NotificationChannelTypesEnum::WEBHOOK:
                $channel = $this->channel;

                if (! $channel) {
                    Log::error('SSL webhook notification failed because the channel is missing', [
                        'notification_setting_id' => $this->id,
                    ]);

                    return false;
                }

                $descriptionText = 'Your SSL certificate for '.$data['url'].' is nearing expiration in '.$data['daysLeft'].' days. Please renew your SSL certificate as soon as possible to avoid security issues. Best regards, Your team '.config('app.name');

                $response = $channel->sendWebhookNotification([
                    'message' => 'Action Required: Renew Your SSL Certificate.',
                    'description' => $descriptionText,
                ]);

                $code = (int) ($response['code'] ?? 0);
                if ($code >= 200 && $code < 300) {
                    Log::info('Webhook Notification successfully sent to '.$response['url']);

                    return true;
                } else {
                    Log::error('Webhook Notification failed sent', ['url' => $response['url'], 'code' => $code]);

                    return false;
                }

            default:
                $unknownChannelType = $channelType?->value ?? (string) $this->channel_type;

                Log::error('Unknown channel type: '.$unknownChannelType);

                return false;
        }
    }

    private function sendEmail($data, $MailClass): bool
    {
        Mail::to($this->address)->send(new $MailClass($data));

        return true;
    }

    public function channel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(NotificationChannels::class, 'notification_channel_id');
    }

    /**
     * Dispatch a sample notification through this setting so the operator can
     * verify the address or webhook is reachable before relying on it for a
     * real incident.
     *
     * @return array{ok: bool, title: string, body: string}
     */
    public function sendTestNotification(): array
    {
        $channelType = $this->resolveChannelType();

        return match ($channelType) {
            NotificationChannelTypesEnum::MAIL => $this->sendTestEmail(),
            NotificationChannelTypesEnum::WEBHOOK => $this->sendTestWebhook(),
            default => [
                'ok' => false,
                'title' => 'Unknown channel type',
                'body' => 'This notification setting does not have a recognised channel type.',
            ],
        };
    }

    /**
     * Hydrated models return an enum instance via the cast; the raw-string
     * fallback exists for unhydrated data sources (factories using setRawAttributes,
     * legacy serialised payloads) where the cast would not have been applied.
     */
    private function resolveChannelType(): ?NotificationChannelTypesEnum
    {
        $value = $this->channel_type;

        if ($value instanceof NotificationChannelTypesEnum) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return NotificationChannelTypesEnum::tryFrom((string) $value);
    }

    /**
     * @return array{ok: bool, title: string, body: string}
     */
    private function sendTestEmail(): array
    {
        if (empty($this->address)) {
            return [
                'ok' => false,
                'title' => 'Test email not sent',
                'body' => 'This notification setting does not have a recipient email address configured.',
            ];
        }

        try {
            Mail::to($this->address)->send(new HealthStatusAlert(
                name: config('app.name').' test alert',
                event: 'test_notification',
                eventLabel: 'Test Notification',
                status: 'ok',
                summary: 'This is a sample alert sent from Checkybot to verify your notification setting. No action is required.',
                url: url('/'),
            ));
        } catch (Throwable $exception) {
            Log::error('Test notification email failed to send', [
                'notification_setting_id' => $this->id,
                'address' => $this->address,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'title' => 'Test email failed',
                'body' => 'The test email could not be sent. Check the application logs for the mail transport error and verify your mail configuration.',
            ];
        }

        return [
            'ok' => true,
            'title' => 'Test email sent',
            'body' => 'A sample alert has been sent to '.$this->address.'. Please check the inbox (and spam folder) to confirm delivery.',
        ];
    }

    /**
     * @return array{ok: bool, title: string, body: string}
     */
    private function sendTestWebhook(): array
    {
        $channel = $this->channel;

        if (! $channel) {
            return [
                'ok' => false,
                'title' => 'Test webhook not sent',
                'body' => 'This notification setting is not linked to a webhook channel anymore. Edit the setting and pick an active channel.',
            ];
        }

        try {
            $response = $channel->sendWebhookNotification([
                'message' => 'Checkybot test notification',
                'description' => 'This is a sample payload sent from Checkybot to verify the "'.$channel->title.'" webhook channel. No action is required.',
            ]);
        } catch (Throwable $exception) {
            Log::error('Test webhook notification failed with unexpected error', [
                'notification_setting_id' => $this->id,
                'channel_id' => $channel->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'title' => 'Test webhook failed',
                'body' => 'The test could not be completed due to an unexpected error. Check the application logs for details.',
            ];
        }

        $code = (int) ($response['code'] ?? 0);

        if ($code >= 200 && $code < 300) {
            return [
                'ok' => true,
                'title' => 'Test webhook delivered',
                'body' => 'The "'.$channel->title.'" webhook responded with HTTP '.$code.'. Confirm the message arrived in the destination channel.',
            ];
        }

        $body = $response['body'] ?? null;
        $detail = is_string($body) ? $body : (json_encode($body) ?: '');

        // sendWebhookNotification() reuses the `code` key for two different
        // namespaces: real HTTP status codes (100–599) on a completed request,
        // and curl errnos (e.g. 60 for SSL, 6 for DNS) when a RequestException
        // is caught. Label them differently so operators can tell apart a 502
        // from a TLS handshake failure.
        if ($code >= 100 && $code < 600) {
            $codeLabel = 'HTTP '.$code;
        } elseif ($code > 0) {
            $codeLabel = 'network error (curl errno '.$code.')';
        } else {
            $codeLabel = 'no response (network or transport error)';
        }

        return [
            'ok' => false,
            'title' => 'Test webhook failed',
            'body' => 'The "'.$channel->title.'" webhook did not respond with a 2xx status. Received: '.$codeLabel.($detail !== '' ? ' — '.$detail : ''),
        ];
    }
}
