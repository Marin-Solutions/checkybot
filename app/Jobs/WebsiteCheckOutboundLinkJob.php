<?php

namespace App\Jobs;

use App\Crawlers\WebsiteOutboundLinkCrawler;
use App\Models\Website;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Spatie\Crawler\Crawler;

class WebsiteCheckOutboundLinkJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public Website $website)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Crawler::create()
                ->setCrawlObserver(new WebsiteOutboundLinkCrawler($this->website))
                ->startCrawling($this->website->getBaseURL());
        } catch (\Exception $e) {
            Log::error('Outbound link check failed for website '.$this->website->url.': '.$e->getMessage());
        }
    }
}
