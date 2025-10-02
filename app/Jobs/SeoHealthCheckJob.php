<?php

namespace App\Jobs;

use App\Crawlers\SeoHealthCheckCrawler;
use App\Models\SeoCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlProfiles\CrawlInternalUrls;
use Spatie\Robots\RobotsTxt;

class SeoHealthCheckJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600; // 1 hour timeout

    public int $tries = 3;

    protected SeoCheck $seoCheck;

    public function __construct(SeoCheck $seoCheck)
    {
        $this->seoCheck = $seoCheck;
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

            // Check robots.txt
            $this->checkRobotsTxt($baseUrl);

            // Create crawler with SEO-specific configuration
            $crawler = Crawler::create()
                ->setCrawlObserver(new SeoHealthCheckCrawler($this->seoCheck))
                ->setCrawlProfile(new CrawlInternalUrls($baseUrl))
                ->setDelayBetweenRequests(1000) // 1 second delay between requests
                ->setUserAgent('CheckyBot SEO Crawler/1.0 (+https://checkybot.com/bot)')
                ->ignoreRobots(false); // Respect robots.txt

            // Start crawling
            $crawler->startCrawling($baseUrl);

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

    protected function checkRobotsTxt(string $baseUrl): void
    {
        try {
            $robotsTxtUrl = rtrim($baseUrl, '/') . '/robots.txt';
            $robots = RobotsTxt::create($robotsTxtUrl);

            Log::info("Robots.txt found for {$baseUrl}: " . ($robots->allows('CheckyBot SEO Crawler') ? 'Allowed' : 'Disallowed'));
        } catch (\Exception $e) {
            Log::warning("Could not fetch robots.txt for {$baseUrl}: " . $e->getMessage());
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
