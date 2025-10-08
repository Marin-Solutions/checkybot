<?php

namespace App\Services;

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Support\Facades\Log;

class SeoHealthCheckService
{
    protected RobotsSitemapService $robotsSitemapService;

    public function __construct(RobotsSitemapService $robotsSitemapService)
    {
        $this->robotsSitemapService = $robotsSitemapService;
    }

    public function startManualCheck(Website $website): SeoCheck
    {
        Log::info("Starting manual SEO health check for website: {$website->url}");

        // Get crawlable URLs from sitemap or base URL
        $crawlableUrls = $this->robotsSitemapService->getCrawlableUrls($website->url);

        if (empty($crawlableUrls)) {
            throw new \Exception("No crawlable URLs found for {$website->url}. Check robots.txt restrictions.");
        }

        Log::info('Found '.count($crawlableUrls)." crawlable URLs for {$website->url}");

        // Create new SEO check record
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
            'total_urls_crawled' => 0,
            'total_crawlable_urls' => count($crawlableUrls),
            'sitemap_used' => count($crawlableUrls) > 1,
            'robots_txt_checked' => true,
            'crawl_summary' => [
                'sitemap_urls_found' => count($crawlableUrls) > 1,
                'robots_txt_checked' => true,
            ],
        ]);

        // Dispatch job to start crawling with specific URLs
        SeoHealthCheckJob::dispatch($seoCheck, $crawlableUrls);

        return $seoCheck;
    }
}
