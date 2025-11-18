<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\SslCertificate\SslCertificate;

class LogUptimeSslJob implements ShouldQueue
{
    use Queueable;

    protected $website;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct($website)
    {
        $this->website = $website;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $website = Website::find($this->website['id']);

        if (! $website) {
            Log::error('Website not found for ID: '.$this->website['id']);

            return;
        }

        if (! $website->uptime_check) {
            return;
        }

        $ssl_expiry_date = null;
        $http_status_code = null;
        $speed = null;

        try {
            // Get SSL expiry date (with error handling)
            try {
                $certificate = SslCertificate::createForHostName($this->website['url']);
                $ssl_expiry_date = $certificate->expirationDate();
            } catch (\Exception $sslException) {
                Log::warning('Could not retrieve SSL certificate for '.$this->website['url'].': '.$sslException->getMessage());
                // Continue without SSL info rather than failing completely
            }

            // Get status code and speed with timeout and retry configuration
            $responseTimeStart = microtime(true);

            try {
                $response = Http::timeout(10) // Set timeout to 10 seconds
                    ->retry(2, 1000, throw: false) // Retry 2 times with 1 second delay, don't throw on failure
                    ->connectTimeout(5) // Connection timeout of 5 seconds
                    ->withoutVerifying() // Don't verify SSL certificates to avoid failures
                    ->get($this->website['url']);

                $responseTimeEnd = microtime(true);
                $http_status_code = $response->status();
                $speed = round(($responseTimeEnd - $responseTimeStart) * 1000);
            } catch (ConnectionException $e) {
                // Handle connection timeout specifically
                Log::warning('Connection timeout for website '.$this->website['url'].': '.$e->getMessage());
                $responseTimeEnd = microtime(true);

                // Record as timeout (status code 0 typically indicates connection failure)
                $http_status_code = 0;
                $speed = round(($responseTimeEnd - $responseTimeStart) * 1000);
            }

            // Create and save the log even if some checks failed
            $websiteLogHistory = new WebsiteLogHistory;
            $websiteLogHistory->website_id = $this->website['id'];
            $websiteLogHistory->ssl_expiry_date = $ssl_expiry_date;
            $websiteLogHistory->http_status_code = $http_status_code;
            $websiteLogHistory->speed = $speed;
            $websiteLogHistory->save();

            // Log successful completion
            Log::info('Successfully logged uptime/SSL for website '.$this->website['url']);

        } catch (\Exception $e) {
            Log::error('Error creating log for website '.$this->website['url'].': '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60]; // Wait 10s, then 30s, then 60s between retries
    }
}
