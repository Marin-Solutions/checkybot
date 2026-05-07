<?php

namespace App\Jobs;

use App\Crawlers\WebsiteOutboundLinkCrawler;
use App\Models\Website;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Spatie\Crawler\Crawler;

class WebsiteCheckOutboundLinkJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const SOURCE_SCHEDULED = 'scheduled';

    public const SOURCE_ON_DEMAND = 'on-demand';

    public function __construct(
        public Website $website,
        public string $source = self::SOURCE_SCHEDULED,
    ) {
        //
    }

    public static function scheduled(Website $website): self
    {
        return new self($website, self::SOURCE_SCHEDULED);
    }

    public static function onDemand(Website $website): self
    {
        return new self($website, self::SOURCE_ON_DEMAND);
    }

    public function uniqueId(): string
    {
        return "website-outbound-link:{$this->source}:{$this->website->getKey()}";
    }

    public function uniqueFor(): int
    {
        return match ($this->source) {
            self::SOURCE_ON_DEMAND => 300,
            default => 86400,
        };
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
