<?php

namespace App\Console\Commands;

use App\Models\SeoCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateSeoCheckComputedColumns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seo:populate-computed-columns';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Populate computed columns for SEO checks to improve performance';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting to populate computed columns for SEO checks...');

        $seoChecks = SeoCheck::all();
        $total = $seoChecks->count();
        $bar = $this->output->createProgressBar($total);

        $bar->start();

        foreach ($seoChecks as $seoCheck) {
            // Calculate counts using efficient queries
            $errorsCount = DB::table('seo_issues')
                ->where('seo_check_id', $seoCheck->id)
                ->where('severity', 'error')
                ->count();

            $warningsCount = DB::table('seo_issues')
                ->where('seo_check_id', $seoCheck->id)
                ->where('severity', 'warning')
                ->count();

            $noticesCount = DB::table('seo_issues')
                ->where('seo_check_id', $seoCheck->id)
                ->where('severity', 'notice')
                ->count();

            $httpErrorsCount = DB::table('seo_crawl_results')
                ->where('seo_check_id', $seoCheck->id)
                ->where('status_code', '>=', 400)
                ->where('status_code', '<', 600)
                ->count();

            // Calculate health score
            $healthScore = 0.0;
            if ($seoCheck->total_urls_crawled > 0) {
                $urlsWithErrors = $httpErrorsCount + $errorsCount;
                $urlsWithoutErrors = $seoCheck->total_urls_crawled - $urlsWithErrors;
                $healthScore = ($urlsWithoutErrors / $seoCheck->total_urls_crawled) * 100;

                // Ensure health score is not negative (edge case where errors > URLs crawled)
                $healthScore = max(0.0, $healthScore);
            }

            // Update the SEO check with computed values
            $seoCheck->update([
                'computed_errors_count' => $errorsCount,
                'computed_warnings_count' => $warningsCount,
                'computed_notices_count' => $noticesCount,
                'computed_http_errors_count' => $httpErrorsCount,
                'computed_health_score' => round($healthScore, 2),
            ]);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Successfully populated computed columns for {$total} SEO checks!");

        return Command::SUCCESS;
    }
}
