<?php

namespace App\Jobs;

use App\Enums\WebsiteServicesEnum;
use App\Mail\EmailReminderSsl;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\SslCertificate\SslCertificate;

class CheckSslExpiryDateJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Website $website
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $certificate = SslCertificate::createForHostName($this->website->url);
        $newExpiryDate = $certificate->expirationDate();
        $currentExpiryDate = Carbon::parse($this->website->ssl_expiry_date);

        if ($newExpiryDate->gt($currentExpiryDate)) {
            $this->website->update(['ssl_expiry_date' => $newExpiryDate]);

            return;
        }

        $user = User::find($this->website->created_by);

        if ($user) {
            $data = [
                'user' => $user,
                'daysLeft' => $this->website->days_left,
                'url' => $this->website->url,
            ];

            /* Individual website notification */
            $individualNotifications = $this->website->notificationChannels
                ->whereIn('inspection', [WebsiteServicesEnum::WEBSITE_CHECK->name, WebsiteServicesEnum::ALL_CHECK->name]);

            if (! empty($individualNotifications)) {
                $individualNotifications->each(function (NotificationSetting $notification) use ($data) {
                    $notification->sendSslNotification('Action Required: Renew Your SSL Certificate.', $data);
                });
            }

            /* Global Notification */
            $globalNotifications = $this->website->user->globalNotificationChannels
                ->whereIn('inspection', [WebsiteServicesEnum::WEBSITE_CHECK->name, WebsiteServicesEnum::ALL_CHECK->name]);

            if (! empty($globalNotifications)) {
                $globalNotifications->each(function (NotificationSetting $notification) use ($data) {
                    $notification->sendSslNotification('Action Required: Renew Your SSL Certificate.', $data);
                });
            } else {
                Mail::to($user)->send(new EmailReminderSsl($data));
            }

            Log::info("SSL expiry reminder sent for website: {$this->website['url']}");
        } else {
            Log::warning("User not found for website: {$this->website['url']}");
        }
    }
}
