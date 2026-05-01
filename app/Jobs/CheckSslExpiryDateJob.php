<?php

namespace App\Jobs;

use App\Enums\WebsiteServicesEnum;
use App\Mail\EmailReminderSsl;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\HealthEventNotificationService;
use App\Services\IntervalParser;
use App\Services\PackageHealthStatusService;
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
    public function handle(
        SslCertificateService $sslCertificateService,
        ?PackageHealthStatusService $statusService = null,
        ?HealthEventNotificationService $notificationService = null,
    ): void {
        $statusService ??= app(PackageHealthStatusService::class);
        $notificationService ??= app(HealthEventNotificationService::class);

        if (! $this->website->ssl_check) {
            return;
        }

        $host = $sslCertificateService->extractHost($this->website->url);
        $port = $sslCertificateService->extractPort($this->website->url);

        if (blank($host)) {
            Log::error('Could not determine SSL host for website '.$this->website->url);
            $this->recordPackageHealth(null, $statusService, $notificationService);

            return;
        }

        try {
            $newExpiryDate = $sslCertificateService->getExpirationDateForHost($host, $port);
        } catch (\Exception $e) {
            Log::error('Could not retrieve SSL certificate for website '.$this->website->url.': '.$e->getMessage());
            $this->recordPackageHealth(null, $statusService, $notificationService);

            return;
        }

        $newExpiryDate = Carbon::parse($newExpiryDate);
        $currentExpiryDate = $this->website->ssl_expiry_date
            ? Carbon::parse($this->website->ssl_expiry_date)
            : null;

        $attributes = [
            'ssl_expiry_date' => $newExpiryDate,
        ];

        if (SslCertificateService::expiryDateChanged($currentExpiryDate, $newExpiryDate)) {
            $attributes['ssl_expiry_reminder_sent_at'] = null;
        }

        $this->website->forceFill($attributes)->save();

        $this->recordPackageHealth($newExpiryDate, $statusService, $notificationService);

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

    private function recordPackageHealth(
        ?CarbonInterface $expiryDate,
        PackageHealthStatusService $statusService,
        HealthEventNotificationService $notificationService,
    ): void {
        if (
            $this->website->source !== 'package'
            || $this->website->uptime_check
            || blank($this->website->package_interval)
            || ! $this->packageIntervalElapsed()
        ) {
            return;
        }

        $status = $statusService->sslStatusFromExpiryDate($expiryDate);
        $summary = $statusService->summaryForSsl($expiryDate);
        $previousStatus = $this->website->current_status;

        WebsiteLogHistory::create([
            'website_id' => $this->website->id,
            'ssl_expiry_date' => $expiryDate,
            'status' => $status,
            'summary' => $summary,
        ]);

        $this->website->forceFill([
            'current_status' => $status,
            'last_heartbeat_at' => now(),
            'stale_at' => null,
            'status_summary' => $summary,
        ])->save();

        if (
            in_array($status, ['warning', 'danger'], true)
            && $previousStatus !== $status
        ) {
            $notificationService->notifyWebsite($this->website, 'heartbeat', $status, $summary);
        } elseif (
            $status === 'healthy'
            && in_array($previousStatus, ['warning', 'danger'], true)
        ) {
            $notificationService->notifyWebsite($this->website, 'recovered', $status, $summary);
        }
    }

    private function packageIntervalElapsed(): bool
    {
        if ($this->website->last_heartbeat_at === null) {
            return true;
        }

        try {
            return $this->website->last_heartbeat_at->lte(
                now()->subMinutes(IntervalParser::toMinutes($this->website->package_interval))
            );
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
