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

        $websiteLogHistory = new WebsiteLogHistory();
        $websiteLogHistory->website_id = $this->website['id'];
        $websiteLogHistory->ssl_expiry_date = $this->ssl_expiry_date;
        $websiteLogHistory->http_status_code = $this->http_status_code;
        $websiteLogHistory->speed = $this->speed;
        $websiteLogHistory->save();

        /*Create system log*/
        Log::info('Log created for website ' . $this->website['url']);
    }
}
