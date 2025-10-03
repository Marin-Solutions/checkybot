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

        Log::info('Found ' . count($crawlableUrls) . " crawlable URLs for {$website->url}");

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

    public function getCrawlSummary(SeoCheck $seoCheck): array
    {
        $results = $seoCheck->crawlResults()->get();

        return [
            'total_urls_crawled' => $results->count(),
            'successful_crawls' => $results->where('status_code', '>=', 200)->where('status_code', '<', 300)->count(),
            'redirects' => $results->where('status_code', '>=', 300)->where('status_code', '<', 400)->count(),
            'client_errors' => $results->where('status_code', '>=', 400)->where('status_code', '<', 500)->count(),
            'server_errors' => $results->where('status_code', '>=', 500)->count(),
            'average_response_time' => $results->whereNotNull('response_time_ms')->avg('response_time_ms'),
            'total_internal_links' => $results->sum('internal_link_count'),
            'total_external_links' => $results->sum('external_link_count'),
        ];
    }
}
