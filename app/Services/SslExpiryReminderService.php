<?php

namespace App\Services;

use App\Enums\WebsiteServicesEnum;
use App\Mail\EmailReminderSsl;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\Website;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SslExpiryReminderService
{
    public function deliverIfDue(Website $website, ?CarbonInterface $expiryDate = null): bool
    {
        if (! $website->ssl_check) {
            return false;
        }

        $expiryDate ??= $website->ssl_expiry_date ? Carbon::parse($website->ssl_expiry_date) : null;
        if ($expiryDate === null || ! $this->shouldSendReminder($expiryDate)) {
            return false;
        }

        if ($this->reminderRecentlySent($website)) {
            Log::info('SSL expiry reminder throttled for website: '.$website->url);

            return false;
        }

        if ($this->reminderDeliverySilenced($website)) {
            Log::info('SSL expiry reminder skipped because website is snoozed: '.$website->url);

            return false;
        }

        $user = User::find($website->created_by);
        if (! $user) {
            Log::warning('User not found for website: '.$website->url);

            return false;
        }

        $data = [
            'user' => $user,
            'daysLeft' => Carbon::now()->diffInDays($expiryDate, false),
            'url' => $website->url,
        ];

        $delivered = false;
        $individualNotifications = $website->notificationChannels()
            ->whereIn('inspection', [WebsiteServicesEnum::WEBSITE_CHECK->name, WebsiteServicesEnum::ALL_CHECK->name])
            ->get();

        if ($individualNotifications->isNotEmpty()) {
            $individualNotifications->each(function (NotificationSetting $notification) use ($data, &$delivered) {
                $delivered = $notification->sendSslNotification('Action Required: Renew Your SSL Certificate.', $data) || $delivered;
            });
        }

        $globalNotifications = $website->user->globalNotificationChannels()
            ->whereIn('inspection', [WebsiteServicesEnum::WEBSITE_CHECK->name, WebsiteServicesEnum::ALL_CHECK->name])
            ->get();

        if ($globalNotifications->isNotEmpty()) {
            $globalNotifications->each(function (NotificationSetting $notification) use ($data, &$delivered) {
                $delivered = $notification->sendSslNotification('Action Required: Renew Your SSL Certificate.', $data) || $delivered;
            });
        } elseif ($individualNotifications->isEmpty()) {
            Mail::to($user)->send(new EmailReminderSsl($data));
            $delivered = true;
        }

        if (! $delivered) {
            Log::warning('SSL expiry reminder had no successful deliveries for website: '.$website->url);

            return false;
        }

        Log::info('SSL expiry reminder sent for website: '.$website->url);
        $website->forceFill(['ssl_expiry_reminder_sent_at' => now()])->save();

        return true;
    }

    private function shouldSendReminder(CarbonInterface $expiryDate): bool
    {
        $daysLeft = Carbon::today()->diffInDays($expiryDate->copy()->startOfDay(), false);

        return in_array((int) $daysLeft, [14, 7, 3, 2, 1, 0], true) || $daysLeft < 0;
    }

    private function reminderRecentlySent(Website $website): bool
    {
        if ($website->ssl_expiry_reminder_sent_at === null) {
            return false;
        }

        return $website->ssl_expiry_reminder_sent_at->gt(now()->subDay());
    }

    private function reminderDeliverySilenced(Website $website): bool
    {
        if (! $website->exists || $website->isDirty('silenced_until')) {
            return $website->isSilenced();
        }

        $silencedUntil = Website::query()
            ->whereKey($website->getKey())
            ->value('silenced_until');

        return $silencedUntil !== null && Carbon::parse($silencedUntil)->isFuture();
    }
}
