<?php

namespace App\Console\Commands;

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\SeoSchedule;
use App\Services\RobotsSitemapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunScheduledSeoChecks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seo:run-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run scheduled SEO health checks';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Checking for scheduled SEO health checks...');

        $schedules = SeoSchedule::due()->with('website')->get();

        if ($schedules->isEmpty()) {
            $this->info('No scheduled SEO checks are due to run.');

            return Command::SUCCESS;
        }

        $this->info("Found {$schedules->count()} scheduled SEO checks to run.");

        $bar = $this->output->createProgressBar($schedules->count());
        $bar->start();

        foreach ($schedules as $schedule) {
            $seoCheck = null;
            $jobDispatched = false;

            try {
                $website = $schedule->website;

                if (SeoCheck::query()
                    ->where('website_id', $schedule->website_id)
                    ->whereIn('status', ['pending', 'running'])
                    ->exists()
                ) {
                    $this->warn("Skipping {$website->url}: a check is already pending or running.");
                    $schedule->advanceNextRun();

                    Log::info("Skipped scheduled SEO check for {$website->url}: existing pending/running check found. Schedule advanced to {$schedule->next_run_at}.");

                    $bar->advance();

                    continue;
                }

                $robotsSitemapService = app(RobotsSitemapService::class);

                // Get crawlable URLs from sitemap or base URL
                $crawlableUrls = $robotsSitemapService->getCrawlableUrls($website->url);

                if (empty($crawlableUrls)) {
                    $summary = 'No crawlable URLs were found. The sitemap may be empty, unavailable, or blocked by robots.txt.';
                    $failedAt = now();

                    SeoCheck::create([
                        'website_id' => $schedule->website_id,
                        'status' => 'failed',
                        'progress' => 0,
                        'total_urls_crawled' => 0,
                        'total_crawlable_urls' => 0,
                        'sitemap_used' => false,
                        'robots_txt_checked' => true,
                        'started_at' => $failedAt,
                        'finished_at' => $failedAt,
                        'failure_summary' => $summary,
                        'failure_context' => [
                            'failure_reason' => 'no_crawlable_urls',
                            'website_url' => $website->url,
                            'schedule_id' => $schedule->id,
                            'scheduled_by' => $schedule->created_by,
                            'checked_at' => $failedAt->toIso8601String(),
                        ],
                        'crawl_summary' => [
                            'scheduled_by' => $schedule->created_by,
                            'schedule_id' => $schedule->id,
                            'is_scheduled' => true,
                            'failure_reason' => 'no_crawlable_urls',
                            'summary' => $summary,
                        ],
                    ]);

                    $schedule->updateNextRun();

                    $this->warn("No crawlable URLs found for {$website->url}. Schedule advanced.");
                    Log::warning("No crawlable URLs found for scheduled check: {$website->url}. Schedule advanced to {$schedule->next_run_at}.");

                    $bar->advance();

                    continue;
                }

                // Create new SEO check
                $seoCheck = SeoCheck::create([
                    'website_id' => $schedule->website_id,
                    'status' => 'pending',
                    'total_crawlable_urls' => count($crawlableUrls),
                    'sitemap_used' => count($crawlableUrls) > 1,
                    'robots_txt_checked' => true,
                    'crawl_summary' => [
                        'scheduled_by' => $schedule->created_by,
                        'schedule_id' => $schedule->id,
                        'is_scheduled' => true,
                    ],
                ]);

                // Dispatch the job with schedule information
                SeoHealthCheckJob::dispatch($seoCheck, $crawlableUrls)->onQueue('seo-checks');
                $jobDispatched = true;

                // Update the schedule
                $schedule->updateNextRun();

                $this->line("Started scheduled SEO check for: {$schedule->website->url}");

                Log::info("Scheduled SEO check started for website: {$schedule->website->url} (Schedule ID: {$schedule->id})");
            } catch (Throwable $e) {
                if ($jobDispatched) {
                    Log::error("Scheduled SEO check was dispatched for website {$schedule->website->url}, but follow-up processing failed: ".$e->getMessage(), [
                        'schedule_id' => $schedule->id,
                        'seo_check_id' => $seoCheck?->id,
                    ]);
                } else {
                    $this->recordStartupFailure($schedule, $e, $seoCheck);
                }

                $this->error("Failed to start SEO check for {$schedule->website->url}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Scheduled SEO checks processing completed.');

        return Command::SUCCESS;
    }

    protected function recordStartupFailure(SeoSchedule $schedule, Throwable $exception, ?SeoCheck $seoCheck = null): void
    {
        $website = $schedule->website;
        $failedAt = now();
        $summary = 'Scheduled SEO check could not start: '.$exception->getMessage();
        $failureContext = [
            'failure_reason' => 'scheduled_startup_failed',
            'website_url' => $website->url,
            'schedule_id' => $schedule->id,
            'scheduled_by' => $schedule->created_by,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'checked_at' => $failedAt->toIso8601String(),
        ];
        $crawlSummary = array_merge($seoCheck?->crawl_summary ?? [], [
            'scheduled_by' => $schedule->created_by,
            'schedule_id' => $schedule->id,
            'is_scheduled' => true,
            'failure_reason' => 'scheduled_startup_failed',
            'summary' => $summary,
        ]);

        try {
            if ($seoCheck) {
                $seoCheck->update([
                    'status' => 'failed',
                    'progress' => 0,
                    'started_at' => $seoCheck->started_at ?? $failedAt,
                    'finished_at' => $failedAt,
                    'failure_summary' => $summary,
                    'failure_context' => $failureContext,
                    'crawl_summary' => $crawlSummary,
                ]);
            } else {
                SeoCheck::create([
                    'website_id' => $schedule->website_id,
                    'status' => 'failed',
                    'progress' => 0,
                    'total_urls_crawled' => 0,
                    'total_crawlable_urls' => 0,
                    'sitemap_used' => false,
                    'robots_txt_checked' => false,
                    'started_at' => $failedAt,
                    'finished_at' => $failedAt,
                    'failure_summary' => $summary,
                    'failure_context' => $failureContext,
                    'crawl_summary' => $crawlSummary,
                ]);
            }
        } catch (Throwable $recordException) {
            Log::error("Failed to record scheduled SEO startup failure for website {$website->url}: ".$recordException->getMessage(), [
                'schedule_id' => $schedule->id,
                'original_exception' => $exception->getMessage(),
            ]);
        }

        try {
            $schedule->updateNextRun();
        } catch (Throwable $scheduleException) {
            Log::error("Failed to advance scheduled SEO check after startup failure for website {$website->url}: ".$scheduleException->getMessage(), [
                'schedule_id' => $schedule->id,
                'original_exception' => $exception->getMessage(),
            ]);

            return;
        }

        Log::error("Failed to start scheduled SEO check for website {$website->url}: ".$exception->getMessage()." Schedule advanced to {$schedule->next_run_at}.", [
            'schedule_id' => $schedule->id,
            'seo_check_id' => $seoCheck?->id,
        ]);
    }
}
