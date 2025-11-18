<?php

namespace App\Models;

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Mail\EmailReminderSsl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

    public function sendSslNotification(?string $message = null, array $data = []): void
    {
        switch ($this->channel_type) {
            case NotificationChannelTypesEnum::MAIL->name:
                $this->sendEmail($data, EmailReminderSsl::class);

                break;

            case NotificationChannelTypesEnum::WEBHOOK->name:
                $descriptionText = 'Your SSL certificate for '.$data['url'].' is nearing expiration in '.$data['daysLeft'].' days. Please renew your SSL certificate as soon as possible to avoid security issues. Best regards, Your team '.config('app.name');

                $response = $this->channel->sendWebhookNotification([
                    'message' => 'Action Required: Renew Your SSL Certificate.',
                    'description' => $descriptionText,
                ]);

                if ($response['code'] === 200) {
                    Log::info('Webhook Notification successfully sent to '.$response['url']);
                } else {
                    Log::error('Webhook Notification failed sent', ['url' => $response['url']]);
                }

                break;

            default:
                Log::error("Unknown channel type: {$this->channel_type}");
        }
    }

    private function sendEmail($data, $MailClass): void
    {
        Mail::to($this->address)->send(new $MailClass($data));
    }

    public function channel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(NotificationChannels::class, 'notification_channel_id');
    }
}
