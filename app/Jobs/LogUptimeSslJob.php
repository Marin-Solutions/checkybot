<?php

namespace App\Jobs;

use App\Enums\RunSource;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\HealthEventNotificationService;
use App\Services\PackageHealthStatusService;
use App\Services\SslCertificateService;
use App\Support\UptimeTransportError;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LogUptimeSslJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $onDemand  When true, the job is treated as an operator-triggered diagnostic run:
     *                          the result is appended to history but the website's live status fields
     *                          (`current_status`, `last_heartbeat_at`, `stale_at`, `status_summary`)
     *                          are left untouched so the scheduler's transition-based alerting baseline
     *                          stays accurate, and no health notifications are sent.
     */
    public function __construct(
        public Website $website,
        public bool $onDemand = false,
    ) {}

    public function uniqueId(): string
    {
        $mode = $this->isOnDemand() ? RunSource::OnDemand->value : RunSource::Scheduled->value;

        return "website-uptime-ssl:{$this->website->getKey()}:{$mode}";
    }

    public function uniqueFor(): int
    {
        if ($this->isOnDemand()) {
            return 60;
        }

        return ($this->website->uptime_interval * 60) + 3600;
    }

    private function isOnDemand(): bool
    {
        // Legacy queued payloads may not include the onDemand flag.
        return isset($this->onDemand) && $this->onDemand;
    }

    /**
     * Execute the job.
     */
    public function handle(SslCertificateService $sslCertificateService): void
    {
        if (! $this->shouldRunForWebsite()) {
            return;
        }

        $statusService = app(PackageHealthStatusService::class);
        $notificationService = app(HealthEventNotificationService::class);
        $shouldRunUptime = (bool) $this->website->uptime_check;
        $shouldRunSsl = (bool) $this->website->ssl_check;

        $ssl_expiry_date = null;
        $http_status_code = null;
        $speed = null;
        $transportError = null;

        try {
            if ($shouldRunSsl) {
                $host = $sslCertificateService->extractHost($this->website->url);
                $port = $sslCertificateService->extractPort($this->website->url);

                if (blank($host)) {
                    Log::warning('Could not determine SSL host for '.$this->website->url);
                } else {
                    try {
                        $ssl_expiry_date = $sslCertificateService->getExpirationDateForHost($host, $port);
                    } catch (\Exception $sslException) {
                        Log::warning('Could not retrieve SSL certificate for '.$this->website->url.': '.$sslException->getMessage());
                    }
                }
            }

            if ($shouldRunUptime) {
                $responseTimeStart = microtime(true);

                try {
                    $response = Http::timeout(10)
                        ->retry(2, 1000, throw: false)
                        ->connectTimeout(5)
                        ->withoutVerifying()
                        ->get($this->website->url);

                    $responseTimeEnd = microtime(true);
                    $http_status_code = $response->status();
                    $speed = round(($responseTimeEnd - $responseTimeStart) * 1000);
                } catch (ConnectionException $e) {
                    $transportError = UptimeTransportError::fromThrowable($e);
                    Log::warning('Transport error for website '.$this->website->url.': '.$e->getMessage(), [
                        'transport_error_type' => $transportError['type']->value,
                        'transport_error_code' => $transportError['code'],
                    ]);
                    $responseTimeEnd = microtime(true);

                    $http_status_code = 0;
                    $speed = round(($responseTimeEnd - $responseTimeStart) * 1000);
                }
            }

            $httpStatus = $shouldRunUptime ? $statusService->websiteStatusFromHttpCode($http_status_code) : null;
            $httpSummary = $shouldRunUptime
                ? ($transportError
                    ? UptimeTransportError::summary($transportError['type'])
                    : $statusService->summaryForWebsite($http_status_code))
                : null;
            $sslExpiryDate = $shouldRunSsl && $ssl_expiry_date !== null
                ? Carbon::parse($ssl_expiry_date)
                : null;
            $sslStatus = $shouldRunSsl
                ? $statusService->sslStatusFromExpiryDate($sslExpiryDate)
                : null;
            $status = $this->statusForEnabledChecks($statusService, $httpStatus, $sslStatus);
            $summary = $this->summaryForCombinedStatus(
                $statusService,
                $status,
                $httpStatus,
                $httpSummary,
                $sslStatus,
                $sslExpiryDate,
            );
            $previousStatus = $this->website->current_status;
            $previousSslExpiryDate = $this->isOnDemand() || ! $this->website->ssl_check
                ? null
                : $this->currentOrLatestKnownSslExpiryDate();

            WebsiteLogHistory::create([
                'website_id' => $this->website->id,
                'ssl_expiry_date' => $ssl_expiry_date,
                'http_status_code' => $http_status_code,
                'speed' => $speed,
                'status' => $status,
                'summary' => $summary,
                'transport_error_type' => $transportError ? $transportError['type']->value : null,
                'transport_error_message' => $transportError['message'] ?? null,
                'transport_error_code' => $transportError['code'] ?? null,
                'run_source' => $this->isOnDemand() ? RunSource::OnDemand : RunSource::Scheduled,
                'is_on_demand' => $this->isOnDemand(),
            ]);

            // On-demand runs keep scheduler-owned live status and notification state unchanged.
            if (! $this->isOnDemand()) {
                $this->syncScheduledSslExpirySnapshot($sslExpiryDate, $previousSslExpiryDate);

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

            Log::info('Successfully logged uptime/SSL for website '.$this->website->url);

        } catch (\Exception $e) {
            Log::error('Error creating log for website '.$this->website->url.': '.$e->getMessage());
            throw $e;
        }
    }

    private function shouldRunForWebsite(): bool
    {
        if ((bool) $this->website->uptime_check) {
            return true;
        }

        return $this->isOnDemand() && (bool) $this->website->ssl_check;
    }

    private function statusForEnabledChecks(
        PackageHealthStatusService $statusService,
        ?string $httpStatus,
        ?string $sslStatus,
    ): string {
        // The fallback should only be reached by legacy or malformed payloads where no enabled
        // check produced evidence; keep the result non-green so operators know it needs review.
        return match (true) {
            $httpStatus !== null && $sslStatus !== null => $statusService->worstStatus($httpStatus, $sslStatus),
            $sslStatus !== null => $sslStatus,
            default => $httpStatus ?? 'warning',
        };
    }

    private function summaryForCombinedStatus(
        PackageHealthStatusService $statusService,
        string $status,
        ?string $httpStatus,
        ?string $httpSummary,
        ?string $sslStatus,
        ?CarbonInterface $sslExpiryDate,
    ): string {
        if ($httpStatus === null) {
            return $statusService->summaryForSsl($sslExpiryDate);
        }

        // SSL is the sole deciding factor, so lead with certificate evidence.
        if ($sslStatus !== null && $status === $sslStatus && $status !== $httpStatus) {
            return $statusService->summaryForSsl($sslExpiryDate);
        }

        // HTTP and SSL share the same non-healthy status; show both when expiry evidence exists.
        if ($sslStatus !== null && $sslExpiryDate !== null && $status === $sslStatus && $status !== 'healthy') {
            return $httpSummary.' '.$statusService->summaryForSsl($sslExpiryDate);
        }

        return $httpSummary ?? 'Website diagnostics completed.';
    }

    private function syncScheduledSslExpirySnapshot(
        ?CarbonInterface $sslExpiryDate,
        ?CarbonInterface $previousSslExpiryDate,
    ): void {
        if (! $this->website->ssl_check) {
            return;
        }

        $attributes = [
            'ssl_expiry_date' => $sslExpiryDate,
        ];

        if (
            $sslExpiryDate !== null
            && SslCertificateService::expiryDateChanged($previousSslExpiryDate, $sslExpiryDate)
        ) {
            $attributes['ssl_expiry_reminder_sent_at'] = null;
        }

        $query = Website::query()
            ->whereKey($this->website->getKey())
            ->where('updated_at', $this->website->getRawOriginal('updated_at'));

        $loadedSslExpiryDate = $this->website->getRawOriginal('ssl_expiry_date');

        if ($loadedSslExpiryDate === null) {
            $query->whereNull('ssl_expiry_date');
        } else {
            $query->where('ssl_expiry_date', $loadedSslExpiryDate);
        }

        $query->update($attributes);
    }

    private function currentOrLatestKnownSslExpiryDate(): ?CarbonInterface
    {
        if ($this->website->ssl_expiry_date) {
            return Carbon::parse($this->website->ssl_expiry_date);
        }

        $latestKnownExpiryDate = $this->website->logHistory()
            ->whereNotNull('ssl_expiry_date')
            ->where(function ($query): void {
                $query->where('is_on_demand', false)
                    ->orWhereNull('is_on_demand');
            })
            ->latest('created_at')
            ->latest('id')
            ->value('ssl_expiry_date');

        return $latestKnownExpiryDate ? Carbon::parse($latestKnownExpiryDate) : null;
    }
}
