<?php

namespace App\Console\Commands;

use App\Models\SeoCheck;
use App\Services\SeoIssueDetectionService;
use Illuminate\Console\Command;

class TestSeoIssueDetection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seo:test-issue-detection {seoCheckId?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test SEO issue detection on a specific SEO check';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $seoCheckId = $this->argument('seoCheckId');

        if (! $seoCheckId) {
            $seoCheck = SeoCheck::latest()->first();
        } else {
            $seoCheck = SeoCheck::find($seoCheckId);
        }

        if (! $seoCheck) {
            $this->error('No SEO check found.');

            return Command::FAILURE;
        }

        $this->info("Testing issue detection for SEO Check ID: {$seoCheck->id}");
        $this->info("Website: {$seoCheck->website->url}");
        $this->info("Status: {$seoCheck->status}");
        $this->info("URLs Crawled: {$seoCheck->total_urls_crawled}");

        // Count existing issues
        $existingIssues = $seoCheck->seoIssues()->count();
        $this->info("Existing Issues: {$existingIssues}");

        // Run issue detection
        $this->info('Running issue detection...');
        $issueDetectionService = new SeoIssueDetectionService;
        $issueDetectionService->detectIssues($seoCheck);

        // Count new issues
        $newIssues = $seoCheck->seoIssues()->count();
        $this->info("New Issues: {$newIssues}");

        if ($newIssues > $existingIssues) {
            $this->info('✅ Issue detection created '.($newIssues - $existingIssues).' new issues!');
        } else {
            $this->warn('⚠️ No new issues were created.');
        }

        // Show issue breakdown
        $errors = $seoCheck->seoIssues()->where('severity', 'error')->count();
        $warnings = $seoCheck->seoIssues()->where('severity', 'warning')->count();
        $notices = $seoCheck->seoIssues()->where('severity', 'notice')->count();

        $this->info('Issue Breakdown:');
        $this->info("  Errors: {$errors}");
        $this->info("  Warnings: {$warnings}");
        $this->info("  Notices: {$notices}");

        // Show health score
        $healthScore = $seoCheck->getHealthScoreAttribute();
        $this->info("Health Score: {$healthScore}%");

        return Command::SUCCESS;
    }
}
