<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\HealthEventNotificationService;
use App\Services\PackageHealthStatusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\SslCertificate\SslCertificate;

class LogUptimeSslJob implements ShouldQueue
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
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! $this->website->uptime_check) {
            return;
        }

        $statusService = app(PackageHealthStatusService::class);
        $notificationService = app(HealthEventNotificationService::class);

        $ssl_expiry_date = null;
        $http_status_code = null;
        $speed = null;
        $host = Website::extractHost($this->website->url);

        try {
            // Get SSL expiry date (with error handling)
            if (blank($host)) {
                Log::warning('Could not determine SSL host for '.$this->website->url);
            } else {
                try {
                    $certificate = SslCertificate::createForHostName($host);
                    $ssl_expiry_date = $certificate->expirationDate();
                } catch (\Exception $sslException) {
                    Log::warning('Could not retrieve SSL certificate for '.$this->website->url.': '.$sslException->getMessage());
                    // Continue without SSL info rather than failing completely
                }
            }

            // Get status code and speed with timeout and retry configuration
            $responseTimeStart = microtime(true);

            try {
                $response = Http::timeout(10) // Set timeout to 10 seconds
                    ->retry(2, 1000, throw: false) // Retry 2 times with 1 second delay, don't throw on failure
                    ->connectTimeout(5) // Connection timeout of 5 seconds
                    ->withoutVerifying() // Don't verify SSL certificates to avoid failures
                    ->get($this->website->url);

                $responseTimeEnd = microtime(true);
                $http_status_code = $response->status();
                $speed = round(($responseTimeEnd - $responseTimeStart) * 1000);
            } catch (ConnectionException $e) {
                // Handle connection timeout specifically
                Log::warning('Connection timeout for website '.$this->website->url.': '.$e->getMessage());
                $responseTimeEnd = microtime(true);

                // Record as timeout (status code 0 typically indicates connection failure)
                $http_status_code = 0;
                $speed = round(($responseTimeEnd - $responseTimeStart) * 1000);
            }

            // Create and save the log even if some checks failed
            $status = $statusService->websiteStatusFromHttpCode($http_status_code);
            $summary = $statusService->summaryForWebsite($http_status_code);
            $previousStatus = $this->website->current_status;

            WebsiteLogHistory::create([
                'website_id' => $this->website->id,
                'ssl_expiry_date' => $ssl_expiry_date,
                'http_status_code' => $http_status_code,
                'speed' => $speed,
                'status' => $status,
                'summary' => $summary,
            ]);

            // On-demand runs are diagnostic only: persist the history row, but leave the live
            // status fields and notification firing to the scheduler so the alert-transition
            // baseline stays accurate.
            if (! ($this->onDemand ?? false)) {
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

            // Log successful completion
            Log::info('Successfully logged uptime/SSL for website '.$this->website->url);

        } catch (\Exception $e) {
            Log::error('Error creating log for website '.$this->website->url.': '.$e->getMessage());
            throw $e;
        }
    }
}
