<?php

namespace App\Jobs;

use App\Models\WebsiteLogHistory;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\SslCertificate\SslCertificate;
use App\Models\Website;

class LogUptimeSslJob implements ShouldQueue
{
    use Queueable;

    protected $website;

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
        
        if (!$website) {
            Log::error("Website not found for ID: " . $this->website['id']);
            return;
        }

        try {
            // Get SSL expiry date
            $certificate = SslCertificate::createForHostName($this->website['url']);
            $ssl_expiry_date = $certificate->expirationDate();

            // Get status code and speed
            $responseTimeStart = microtime(true);
            $response = Http::get($this->website['url']);
            $responseTimeEnd = microtime(true);
            
            $http_status_code = $response->status();
            $speed = round(($responseTimeEnd - $responseTimeStart) * 1000);

            // Create and save the log
            $websiteLogHistory = new WebsiteLogHistory();
            $websiteLogHistory->website_id = $this->website['id'];
            $websiteLogHistory->ssl_expiry_date = $ssl_expiry_date;
            $websiteLogHistory->http_status_code = $http_status_code;
            $websiteLogHistory->speed = $speed;
            $websiteLogHistory->save();

            /*Create system log*/
        } catch (\Exception $e) {
            Log::error('Error creating log for website ' . $this->website['url'] . ': ' . $e->getMessage());
            throw $e;
        }
    }
}
