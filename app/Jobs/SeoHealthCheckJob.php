<?php

namespace App\Jobs;

use App\Crawlers\SeoHealthCheckCrawler;
use App\Models\SeoCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlProfiles\CrawlInternalUrls;

class SeoHealthCheckJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600; // 1 hour timeout

    public int $tries = 3;

    protected SeoCheck $seoCheck;

    protected array $crawlableUrls;

    public function __construct(SeoCheck $seoCheck, array $crawlableUrls = [])
    {
        $this->seoCheck = $seoCheck;
        $this->crawlableUrls = $crawlableUrls;
    }

    public function handle(): void
    {
        Log::info("Starting SEO health check for website: {$this->seoCheck->website->url}");

        try {
            // Update status to running
            $this->seoCheck->update([
                'status' => 'running',
                'started_at' => now(),
            ]);

            $website = $this->seoCheck->website;
            $baseUrl = $website->getBaseURL();

            // Create crawler with SEO-specific configuration
            $crawler = Crawler::create()
                ->setCrawlObserver(new SeoHealthCheckCrawler($this->seoCheck))
                ->setCrawlProfile(new CrawlInternalUrls($baseUrl))
                ->setDelayBetweenRequests(1000) // 1 second delay between requests
                ->setUserAgent('CheckyBot SEO Crawler/1.0 (+https://checkybot.com/bot)')
                ->ignoreRobots(false); // Respect robots.txt

            // Start crawling from specific URLs if provided, otherwise from base URL
            if (! empty($this->crawlableUrls)) {
                foreach ($this->crawlableUrls as $url) {
                    $crawler->startCrawling($url);
                }
            } else {
                $crawler->startCrawling($baseUrl);
            }

            Log::info("SEO health check completed successfully for website: {$website->url}");
        } catch (\Exception $e) {
            Log::error("SEO health check failed for website {$this->seoCheck->website->url}: " . $e->getMessage());

            // Update status to failed
            $this->seoCheck->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);

            throw $e; // Re-throw to mark job as failed
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("SEO health check job failed for website {$this->seoCheck->website->url}: " . $exception->getMessage());

        $this->seoCheck->update([
            'status' => 'failed',
            'finished_at' => now(),
        ]);
    }
}
