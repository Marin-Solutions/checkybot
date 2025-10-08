<?php

namespace App\Console\Commands;

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\SeoSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
            try {
                // Create new SEO check
                $seoCheck = SeoCheck::create([
                    'website_id' => $schedule->website_id,
                    'status' => 'pending',
                ]);

                // Dispatch the job
                SeoHealthCheckJob::dispatch($seoCheck);

                // Update the schedule
                $schedule->updateNextRun();

                $this->line("Started SEO check for: {$schedule->website->url}");

                Log::info("Scheduled SEO check started for website: {$schedule->website->url}");
            } catch (\Exception $e) {
                $this->error("Failed to start SEO check for {$schedule->website->url}: ".$e->getMessage());

                Log::error("Failed to start scheduled SEO check for website {$schedule->website->url}: ".$e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Scheduled SEO checks processing completed.');

        return Command::SUCCESS;
    }
}
