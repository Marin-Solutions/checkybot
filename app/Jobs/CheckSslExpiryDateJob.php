<?php

namespace App\Jobs;

use App\Enums\RunSource;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\CheckerOwnership;
use App\Services\HealthEventNotificationService;
use App\Services\IntervalParser;
use App\Services\PackageHealthStatusService;
use App\Services\SslCertificateService;
use App\Services\SslExpiryReminderService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Spatie\SslCertificate\Exceptions\CouldNotDownloadCertificate;

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

        $ownership = app(CheckerOwnership::class);
        $lockKey = 'ssl:'.$this->website->getKey();
        $lockToken = $ownership->acquire('ssl', $lockKey, 90);
        if ($lockToken === null) {
            return;
        }

        try {
            $this->checkCertificate($sslCertificateService, $statusService, $notificationService);
        } finally {
            $ownership->release($lockKey, $lockToken);
        }
    }

    private function checkCertificate(
        SslCertificateService $sslCertificateService,
        PackageHealthStatusService $statusService,
        HealthEventNotificationService $notificationService,
    ): void {
        if (! $this->website->ssl_check) {
            return;
        }

        $host = $sslCertificateService->extractHost($this->website->url);
        $port = $sslCertificateService->extractPort($this->website->url);

        if (blank($host)) {
            Log::error('Could not determine SSL host for website '.$this->website->url);
            $this->recordSslOnlyHealth(null, $statusService, $notificationService);

            return;
        }

        try {
            $newExpiryDate = $sslCertificateService->getExpirationDateForHost($host, $port);
        } catch (\Exception $e) {
            $context = [
                'website_id' => $this->website->id,
                'url' => $this->website->url,
                'host' => $host,
                'port' => $port,
                'monitor' => 'ssl_expiry',
            ];

            if ($e instanceof CouldNotDownloadCertificate) {
                Log::warning('Could not retrieve SSL certificate for website '.$this->website->url.': '.$e->getMessage(), $context);
            } else {
                Log::error('Could not retrieve SSL certificate for website '.$this->website->url.': '.$e->getMessage(), $context);
            }

            $this->recordSslOnlyHealth(null, $statusService, $notificationService);

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

        $this->recordSslOnlyHealth($newExpiryDate, $statusService, $notificationService);
        app(SslExpiryReminderService::class)->deliverIfDue($this->website, $newExpiryDate);
    }

    private function recordSslOnlyHealth(
        ?CarbonInterface $expiryDate,
        PackageHealthStatusService $statusService,
        HealthEventNotificationService $notificationService,
    ): void {
        if (
            $this->website->uptime_check
            || ! $this->sslOnlyHealthIntervalElapsed()
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
            'run_source' => RunSource::Scheduled,
            'is_on_demand' => false,
        ]);

        $this->website->forceFill([
            'current_status' => $status,
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

    private function sslOnlyHealthIntervalElapsed(): bool
    {
        $interval = $this->website->source === 'package'
            ? $this->website->package_interval
            : $this->website->uptime_interval;

        if (blank($interval)) {
            return false;
        }

        $latestRunAt = $this->website->latestScheduledLogHistory()->first()?->created_at;

        if ($latestRunAt === null) {
            return true;
        }

        try {
            return $latestRunAt->lte(
                now()->subMinutes($this->intervalToMinutes($interval))
            );
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function intervalToMinutes(string|int $interval): int
    {
        if (is_int($interval) || ctype_digit($interval)) {
            $minutes = (int) $interval;

            if ($minutes < 1) {
                throw new \InvalidArgumentException('Interval value must be greater than zero.');
            }

            return $minutes;
        }

        return IntervalParser::toMinutes($interval);
    }
}
