<?php

    namespace App\Jobs;

    use App\Crawlers\WebsiteOutboundLinkCrawler;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Queue\Queueable;
    use Illuminate\Support\Facades\Log;
    use Spatie\Crawler\Crawler;

    class WebsiteCheckOutbondLinkJob implements ShouldQueue
    {
        use Queueable;

        protected $website;

        /**
         * Create a new job instance.
         */
        public function __construct( $website )
        {
            $this->website = $website;
        }

        /**
         * Execute the job.
         */
        public function handle(): void
        {
            Crawler::create()
                ->setCrawlObserver(new WebsiteOutboundLinkCrawler($this->website))
                ->startCrawling($this->website->getBaseURL());
        }
    }
