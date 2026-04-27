<?php

namespace App\Jobs;

use App\Enums\WebsiteServicesEnum;
use App\Mail\EmailReminderSsl;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\Website;
use App\Services\SslCertificateService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckSslExpiryDateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Website $website
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SslCertificateService $sslCertificateService): void
    {
        $host = $sslCertificateService->extractHost($this->website->url);
        $port = $sslCertificateService->extractPort($this->website->url);

        if (blank($host)) {
            Log::error('Could not determine SSL host for website '.$this->website->url);

            return;
        }

        try {
            $newExpiryDate = $sslCertificateService->getExpirationDateForHost($host, $port);
        } catch (\Exception $e) {
            Log::error('Could not retrieve SSL certificate for website '.$this->website->url.': '.$e->getMessage());

            return;
        }

        $newExpiryDate = Carbon::parse($newExpiryDate);
        $currentExpiryDate = $this->website->ssl_expiry_date
            ? Carbon::parse($this->website->ssl_expiry_date)
            : null;

        $this->website->forceFill([
            'ssl_expiry_date' => $newExpiryDate,
            'ssl_expiry_reminder_sent_at' => $this->expiryDateChanged($currentExpiryDate, $newExpiryDate)
                ? null
                : $this->website->ssl_expiry_reminder_sent_at,
        ])->save();

        if (! $this->shouldSendReminder($newExpiryDate)) {
            return;
        }

        if ($this->reminderRecentlySent()) {
            Log::info('SSL expiry reminder throttled for website: '.$this->website->url);

            return;
        }

        $user = User::find($this->website->created_by);

        if ($user) {
            $daysLeft = Carbon::now()->diffInDays($newExpiryDate, false);

            $data = [
                'user' => $user,
                'daysLeft' => $daysLeft,
                'url' => $this->website->url,
            ];

            $delivered = false;

            /* Individual website notification */
            $individualNotifications = $this->website->notificationChannels()
                ->whereIn('inspection', [WebsiteServicesEnum::WEBSITE_CHECK->name, WebsiteServicesEnum::ALL_CHECK->name])
                ->get();

            if ($individualNotifications->isNotEmpty()) {
                $individualNotifications->each(function (NotificationSetting $notification) use ($data, &$delivered) {
                    $delivered = $notification->sendSslNotification('Action Required: Renew Your SSL Certificate.', $data) || $delivered;
                });
            }

            /* Global Notification */
            $globalNotifications = $this->website->user->globalNotificationChannels()
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
                Log::warning('SSL expiry reminder had no successful deliveries for website: '.$this->website->url);

                return;
            }

            Log::info('SSL expiry reminder sent for website: '.$this->website->url);

            $this->website->forceFill(['ssl_expiry_reminder_sent_at' => now()])->save();
        } else {
            Log::warning('User not found for website: '.$this->website->url);
        }
    }

    private function expiryDateChanged(?CarbonInterface $currentExpiryDate, CarbonInterface $newExpiryDate): bool
    {
        return $currentExpiryDate === null || ! $currentExpiryDate->isSameDay($newExpiryDate);
    }

    private function shouldSendReminder(CarbonInterface $expiryDate): bool
    {
        $daysLeft = Carbon::today()->diffInDays($expiryDate->copy()->startOfDay(), false);

        return in_array((int) $daysLeft, [14, 7, 3, 2, 1, 0], true) || $daysLeft < 0;
    }

    private function reminderRecentlySent(): bool
    {
        if ($this->website->ssl_expiry_reminder_sent_at === null) {
            return false;
        }

        return $this->website->ssl_expiry_reminder_sent_at->gt(now()->subDay());
    }
}
