<?php

namespace App\Jobs;

use App\Crawlers\SeoHealthCheckCrawler;
use App\Mail\SeoCheckCompleted;
use App\Models\SeoCheck;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Spatie\Crawler\Crawler;
use Spatie\Crawler\CrawlProfiles\CrawlInternalUrls;

class SeoHealthCheckJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900; // 15 minutes timeout

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
        Log::info("Starting SEO health check for SEO check ID: {$this->seoCheck->id}");

        try {
            Log::info('Loading website relationship...');
            $website = $this->seoCheck->website;
            Log::info("Website loaded: {$website->url}");
        } catch (\Exception $e) {
            Log::error('Error loading website: '.$e->getMessage());
            throw $e;
        }

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

            try {
                Log::info("Starting crawl for {$baseUrl}");

                // Start crawling from specific URLs if provided, otherwise from base URL
                if (! empty($this->crawlableUrls)) {
                    foreach ($this->crawlableUrls as $url) {
                        Log::info("Starting crawl for specific URL: {$url}");
                        $crawler->startCrawling($url);
                    }
                } else {
                    Log::info("Starting crawl for base URL: {$baseUrl}");
                    $crawler->startCrawling($baseUrl);
                }

                Log::info("SEO health check completed successfully for website: {$website->url}");

                // Send email notification if this was a scheduled check
                $this->sendNotificationIfScheduled();
            } catch (\Exception $crawlerException) {
                Log::warning('SEO Crawler encountered an exception but may have completed: '.$crawlerException->getMessage());

                // Check if the crawler actually completed by looking at the status
                $this->seoCheck->refresh();
                if ($this->seoCheck->status === 'completed') {
                    Log::info("SEO health check completed successfully despite exception for website: {$website->url}");

                    // Send email notification if this was a scheduled check
                    $this->sendNotificationIfScheduled();

                    return; // Don't throw exception if it actually completed
                }

                // If not completed, re-throw the exception
                throw $crawlerException;
            }
        } catch (\Exception $e) {
            Log::error("SEO health check failed for website {$this->seoCheck->website->url}: ".$e->getMessage());

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
        Log::error("SEO health check job failed for website {$this->seoCheck->website->url}: ".$exception->getMessage());

        $this->seoCheck->update([
            'status' => 'failed',
            'finished_at' => now(),
        ]);

        // Broadcast failure event
        try {
            broadcast(new \App\Events\CrawlFailed(
                seoCheckId: $this->seoCheck->id,
                totalUrlsCrawled: $this->seoCheck->total_urls_crawled ?? 0,
                errorMessage: $exception->getMessage()
            ));
        } catch (\Exception $e) {
            Log::warning('Failed to broadcast crawl failure event: '.$e->getMessage());
        }
    }

    /**
     * Send email notification if this check was scheduled
     */
    protected function sendNotificationIfScheduled(): void
    {
        try {
            $this->seoCheck->refresh();
            $crawlSummary = $this->seoCheck->crawl_summary ?? [];
            $isScheduled = $crawlSummary['is_scheduled'] ?? false;

            if (! $isScheduled) {
                return; // Only send for scheduled checks
            }

            $website = $this->seoCheck->website;
            $scheduledBy = $crawlSummary['scheduled_by'] ?? null;

            // Send to user who configured the schedule
            if ($scheduledBy) {
                $user = \App\Models\User::find($scheduledBy);
                if ($user && $user->email) {
                    Mail::to($user->email)->send(new SeoCheckCompleted($this->seoCheck, isScheduled: true));
                    Log::info("Sent scheduled SEO check completion email to: {$user->email}");
                }
            }

            // Also send to website owner if different from scheduler
            if ($website->user && $website->user->id !== $scheduledBy) {
                Mail::to($website->user->email)->send(new SeoCheckCompleted($this->seoCheck, isScheduled: true));
                Log::info("Sent scheduled SEO check completion email to website owner: {$website->user->email}");
            }
        } catch (\Exception $e) {
            Log::error('Failed to send SEO check completion email: '.$e->getMessage());
            // Don't throw - email failure shouldn't fail the job
        }
    }
}
