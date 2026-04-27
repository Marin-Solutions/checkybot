<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\WebsiteLogHistory;
use App\Services\HealthEventNotificationService;
use App\Services\PackageHealthStatusService;
use App\Services\SslCertificateService;
use App\Support\UptimeTransportError;
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
        $mode = $this->isOnDemand() ? 'on-demand' : 'scheduled';

        return "website-uptime-ssl:{$this->website->getKey()}:{$mode}";
    }

    public function uniqueFor(): int
    {
        return $this->website->uptime_interval * 60;
    }

    private function isOnDemand(): bool
    {
        // Queued payloads serialized before this flag existed leave the typed property uninitialized.
        return $this->onDemand ?? false;
    }

    /**
     * Execute the job.
     */
    public function handle(SslCertificateService $sslCertificateService): void
    {
        if (! $this->website->uptime_check) {
            return;
        }

        $statusService = app(PackageHealthStatusService::class);
        $notificationService = app(HealthEventNotificationService::class);

        $ssl_expiry_date = null;
        $http_status_code = null;
        $speed = null;
        $transportError = null;
        $host = $sslCertificateService->extractHost($this->website->url);
        $port = $sslCertificateService->extractPort($this->website->url);

        try {
            if (blank($host)) {
                Log::warning('Could not determine SSL host for '.$this->website->url);
            } else {
                try {
                    $ssl_expiry_date = $sslCertificateService->getExpirationDateForHost($host, $port);
                } catch (\Exception $sslException) {
                    Log::warning('Could not retrieve SSL certificate for '.$this->website->url.': '.$sslException->getMessage());
                }
            }

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

            $status = $statusService->websiteStatusFromHttpCode($http_status_code);
            $summary = $transportError
                ? UptimeTransportError::summary($transportError['type'])
                : $statusService->summaryForWebsite($http_status_code);
            $previousStatus = $this->website->current_status;

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
            ]);

            // On-demand runs keep scheduler-owned live status and notification state unchanged.
            if (! $this->isOnDemand()) {
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
}
