<?php

namespace App\Services;

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        $existingCheck = SeoCheck::query()
            ->where('website_id', $website->id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        if ($existingCheck) {
            throw new \Exception('A check is already running for this website.');
        }

        try {
            // Get crawlable URLs from sitemap or base URL
            $crawlableUrls = $this->robotsSitemapService->getCrawlableUrls($website->url);
        } catch (Throwable $exception) {
            $this->recordManualStartupFailure(
                $website,
                'Manual SEO check could not start: '.$exception->getMessage(),
                [
                    'failure_reason' => 'manual_startup_failed',
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                ],
                robotsTxtChecked: false
            );

            throw $exception;
        }

        if (empty($crawlableUrls)) {
            $summary = 'No crawlable URLs were found. The sitemap may be empty, unavailable, or blocked by robots.txt.';

            $this->recordManualStartupFailure(
                $website,
                $summary,
                ['failure_reason' => 'no_crawlable_urls'],
                robotsTxtChecked: true
            );

            throw new \Exception("No crawlable URLs found for {$website->url}. Check robots.txt restrictions.");
        }

        Log::info('Found '.count($crawlableUrls)." crawlable URLs for {$website->url}");

        $seoCheck = DB::transaction(function () use ($website, $crawlableUrls) {
            Website::query()
                ->whereKey($website->id)
                ->lockForUpdate()
                ->first();

            $existingCheck = SeoCheck::query()
                ->where('website_id', $website->id)
                ->whereIn('status', ['pending', 'running'])
                ->exists();

            if ($existingCheck) {
                throw new \Exception('A check is already running for this website.');
            }

            return SeoCheck::create([
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
        });

        try {
            // Dispatch job to start crawling with specific URLs
            SeoHealthCheckJob::dispatch($seoCheck, $crawlableUrls)->onQueue('seo-checks');
        } catch (Throwable $exception) {
            $this->recordManualStartupFailure(
                $website,
                'Manual SEO check could not be dispatched: '.$exception->getMessage(),
                [
                    'failure_reason' => 'manual_dispatch_failed',
                    'exception_class' => get_class($exception),
                    'exception_message' => $exception->getMessage(),
                    'seo_check_id' => $seoCheck->id,
                ],
                robotsTxtChecked: true,
                seoCheck: $seoCheck
            );

            throw $exception;
        }

        return $seoCheck;
    }

    protected function recordManualStartupFailure(
        Website $website,
        string $summary,
        array $context,
        bool $robotsTxtChecked,
        ?SeoCheck $seoCheck = null
    ): SeoCheck {
        $failedAt = now();
        $userId = auth()->id() ?: $website->created_by;
        $failureContext = array_merge([
            'website_url' => $website->url,
            'manual_by' => $userId,
            'checked_at' => $failedAt->toIso8601String(),
        ], $context);
        $crawlSummary = array_merge($seoCheck?->crawl_summary ?? [], [
            'manual_by' => $userId,
            'is_manual' => true,
            'failure_reason' => $failureContext['failure_reason'] ?? 'manual_startup_failed',
            'summary' => $summary,
        ]);

        if ($seoCheck) {
            $seoCheck->update([
                'status' => SeoCheck::STATUS_FAILED,
                'progress' => 0,
                'started_at' => $seoCheck->started_at ?? $failedAt,
                'finished_at' => $failedAt,
                'failure_summary' => $summary,
                'failure_context' => $failureContext,
                'crawl_summary' => $crawlSummary,
            ]);

            return $seoCheck->refresh();
        }

        return SeoCheck::create([
            'website_id' => $website->id,
            'status' => SeoCheck::STATUS_FAILED,
            'progress' => 0,
            'total_urls_crawled' => 0,
            'total_crawlable_urls' => 0,
            'sitemap_used' => false,
            'robots_txt_checked' => $robotsTxtChecked,
            'started_at' => $failedAt,
            'finished_at' => $failedAt,
            'failure_summary' => $summary,
            'failure_context' => $failureContext,
            'crawl_summary' => $crawlSummary,
        ]);
    }
}
