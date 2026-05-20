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
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LogUptimeSslJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Queueable;

    /**
     * @param  bool  $onDemand  When true, the run is labeled as a manual run in history.
     */
    public string $diagnosticRunId;

    public function __construct(
        public Website $website,
        public bool $onDemand = false,
        ?string $diagnosticRunId = null,
    ) {
        $this->diagnosticRunId = $diagnosticRunId ?? ($this->onDemand ? (string) Str::uuid() : '');
    }

    public function uniqueId(): string
    {
        $mode = $this->isOnDemand() ? RunSource::OnDemand->value : RunSource::Scheduled->value;

        if ($this->isOnDemand()) {
            $runId = isset($this->diagnosticRunId) && $this->diagnosticRunId !== ''
                ? $this->diagnosticRunId
                : 'legacy';

            return "website-uptime-ssl:{$this->website->getKey()}:{$mode}:{$runId}";
        }

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
        if ($this->batch()?->cancelled()) {
            $this->clearQueuedDiagnostic();

            return;
        }

        $queuedSslExpiryDate = $this->website->getRawOriginal('ssl_expiry_date');
        $freshWebsite = $this->website->fresh();

        if (! $freshWebsite instanceof Website) {
            $this->clearQueuedDiagnostic();

            return;
        }

        $this->website->uptime_check = $freshWebsite->uptime_check;
        $this->website->ssl_check = $freshWebsite->ssl_check;

        if (! $this->shouldRunForWebsite()) {
            $this->clearQueuedDiagnostic();

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
            if (! Website::query()->whereKey($this->website->getKey())->exists()) {
                Log::info('Skipped uptime/SSL history write because website no longer exists.', [
                    'website_id' => $this->website->getKey(),
                ]);

                return;
            }

            try {
                $history = WebsiteLogHistory::create([
                    'website_id' => $this->website->getKey(),
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
            } catch (QueryException $exception) {
                if (! $this->isForeignKeyConstraintViolation($exception)) {
                    throw $exception;
                }

                Log::info('Skipped uptime/SSL history write because website no longer exists.', [
                    'website_id' => $this->website->getKey(),
                ]);

                return;
            }

            $liveUpdate = DB::transaction(function () use ($history, $queuedSslExpiryDate, $sslExpiryDate, $status, $summary): array {
                $lockedWebsite = Website::query()
                    ->whereKey($this->website->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $lockedWebsite instanceof Website) {
                    return [
                        'updated' => false,
                        'previous_status' => null,
                    ];
                }

                $this->website = $lockedWebsite;
                $previousStatus = $lockedWebsite->current_status;
                $previousSslExpiryDate = ! $lockedWebsite->ssl_check
                    ? null
                    : $this->currentOrLatestKnownSslExpiryDate($lockedWebsite, $history);

                $this->syncScheduledSslExpirySnapshot($sslExpiryDate, $previousSslExpiryDate, $queuedSslExpiryDate);

                $lockedWebsite->forceFill([
                    'current_status' => $status,
                    'status_summary' => $summary,
                ])->save();

                return [
                    'updated' => true,
                    'previous_status' => $previousStatus,
                ];
            });

            if (! $liveUpdate['updated']) {
                Log::info('Skipped uptime/SSL live update because website no longer exists.', [
                    'website_id' => $this->website->getKey(),
                ]);

                return;
            }

            $previousStatus = $liveUpdate['previous_status'];

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

            Log::info('Successfully logged uptime/SSL for website '.$this->website->url);

        } catch (\Exception $e) {
            Log::error('Error creating log for website '.$this->website->url.': '.$e->getMessage());
            throw $e;
        } finally {
            $this->clearQueuedDiagnostic();
        }
    }

    private function clearQueuedDiagnostic(): void
    {
        if (! $this->isOnDemand()) {
            return;
        }

        Website::query()
            ->whereKey($this->website->getKey())
            ->update(['diagnostic_queued_at' => null]);
    }

    private function shouldRunForWebsite(): bool
    {
        if ((bool) $this->website->uptime_check) {
            return true;
        }

        return $this->isOnDemand() && (bool) $this->website->ssl_check;
    }

    /**
     * Resolve the recorded status from whichever checks produced evidence.
     *
     * Returns warning only for legacy or malformed payloads where no enabled check
     * produced evidence, keeping the result non-green so operators know it needs review.
     */
    private function statusForEnabledChecks(
        PackageHealthStatusService $statusService,
        ?string $httpStatus,
        ?string $sslStatus,
    ): string {
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
        mixed $queuedSslExpiryDate,
    ): void {
        if (! $this->website->ssl_check) {
            return;
        }

        if ($sslExpiryDate === null) {
            $attributes = [
                'ssl_expiry_date' => null,
            ];
        } else {
            $attributes = [
                'ssl_expiry_date' => $sslExpiryDate,
            ];
        }

        if (
            $sslExpiryDate !== null
            &&
            SslCertificateService::expiryDateChanged($previousSslExpiryDate, $sslExpiryDate)
        ) {
            $attributes['ssl_expiry_reminder_sent_at'] = null;
        }

        $query = Website::query()
            ->whereKey($this->website->getKey())
            ->where('updated_at', $this->website->getRawOriginal('updated_at'));

        if ($queuedSslExpiryDate === null) {
            $query->whereNull('ssl_expiry_date');
        } else {
            $query->where('ssl_expiry_date', $queuedSslExpiryDate);
        }

        $query->update($attributes);
    }

    private function currentOrLatestKnownSslExpiryDate(Website $website, ?WebsiteLogHistory $currentHistory = null): ?CarbonInterface
    {
        if ($website->ssl_expiry_date) {
            return Carbon::parse($website->ssl_expiry_date);
        }

        $latestKnownExpiryDate = $website->logHistory()
            ->when(
                $currentHistory instanceof WebsiteLogHistory,
                fn ($query) => $query->whereKeyNot($currentHistory->getKey()),
            )
            ->whereNotNull('ssl_expiry_date')
            ->latest('created_at')
            ->latest('id')
            ->value('ssl_expiry_date');

        return $latestKnownExpiryDate ? Carbon::parse($latestKnownExpiryDate) : null;
    }

    private function isForeignKeyConstraintViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo;
        $sqlState = (string) ($errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($errorInfo[1] ?? 0);

        return in_array($sqlState, ['23000', '23503'], true)
            && in_array($driverCode, [0, 19, 1452, 23503], true);
    }
}
