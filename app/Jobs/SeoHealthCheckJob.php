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

    public int $timeout = 300; // 5 minutes timeout

    public int $tries = 1; // Only try once to prevent stuck jobs

    public SeoCheck $seoCheck;

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
            $website = $this->seoCheck->website;
            $baseUrl = $website->getBaseURL();

            // Get crawlable URLs from sitemap or robots.txt
            $robotsSitemapService = app(\App\Services\RobotsSitemapService::class);
            $sitemapUrls = $robotsSitemapService->getSitemapUrls($baseUrl);
            $crawlableUrls = $robotsSitemapService->getCrawlableUrls($baseUrl);

            Log::info("SEO Job: Base URL: {$baseUrl}");
            Log::info("SEO Job: Sitemap URLs found: " . count($sitemapUrls));
            Log::info("SEO Job: Crawlable URLs found: " . count($crawlableUrls));
            if (!empty($sitemapUrls)) {
                Log::info("SEO Job: First few sitemap URLs: " . implode(', ', array_slice($sitemapUrls, 0, 5)));
            }
            if (!empty($crawlableUrls)) {
                Log::info("SEO Job: First few crawlable URLs: " . implode(', ', array_slice($crawlableUrls, 0, 5)));
            }

            // Determine total URLs and crawling strategy
            $totalUrls = 0;
            $sitemapUsed = false;

            if (! empty($sitemapUrls)) {
                // Option 1: Use sitemap URLs (best approach)
                $totalUrls = count($sitemapUrls);
                $sitemapUsed = true;
                $this->seoCheck->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'total_crawlable_urls' => $totalUrls,
                    'sitemap_used' => true,
                    'robots_txt_checked' => true,
                    'crawl_summary' => [
                        'sitemap_urls_found' => $totalUrls,
                        'robots_txt_checked' => true,
                        'crawl_strategy' => 'sitemap_preload',
                    ],
                ]);

                Log::info("SEO Crawler: Using sitemap with {$totalUrls} URLs for {$baseUrl}");
            } else {
                // Option 2: Dynamic discovery (fallback)
                $totalUrls = 1; // Start with base URL, will be updated dynamically
                $sitemapUsed = false;
                $this->seoCheck->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'total_crawlable_urls' => $totalUrls,
                    'sitemap_used' => false,
                    'robots_txt_checked' => true,
                    'crawl_summary' => [
                        'sitemap_urls_found' => 0,
                        'robots_txt_checked' => true,
                        'crawl_strategy' => 'dynamic_discovery',
                    ],
                ]);

                Log::info("SEO Crawler: No sitemap found, using dynamic discovery for {$baseUrl}");
            }

            // Create crawler with default Spatie configuration
            $crawler = Crawler::create()
                ->setCrawlObserver(new SeoHealthCheckCrawler($this->seoCheck))
                ->setCrawlProfile(new CrawlInternalUrls($baseUrl));

            // Start crawling based on strategy
            if ($sitemapUsed && ! empty($sitemapUrls)) {
                // Strategy 1: Preload from sitemap - crawl all sitemap URLs
                Log::info("Starting crawl with {$totalUrls} preloaded URLs from sitemap");
                foreach ($sitemapUrls as $url) {
                    $crawler->startCrawling($url);
                }
            } else {
                // Strategy 2: Dynamic discovery - start from base URL and discover as we go
                Log::info('Starting dynamic discovery crawl from base URL');
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
