<?php

namespace App\Console\Commands;

use App\Models\SeoCheck;
use App\Models\SeoCrawlResult;
use App\Models\SeoIssue;
use Illuminate\Console\Command;

class CheckCrawlResults extends Command
{
    protected $signature = 'seo:check-results {seo_check_id}';
    protected $description = 'Check crawl results for a specific SEO check';

    public function handle()
    {
        $seoCheckId = $this->argument('seo_check_id');
        $seoCheck = SeoCheck::find($seoCheckId);

        if (!$seoCheck) {
            $this->error("SEO Check {$seoCheckId} not found");
            return;
        }

        $this->info("SEO Check {$seoCheckId} - Status: {$seoCheck->status}");
        $this->info("URLs Crawled: {$seoCheck->total_urls_crawled}");

        $totalResults = SeoCrawlResult::where('seo_check_id', $seoCheckId)->count();
        $resultsWithHtml = SeoCrawlResult::where('seo_check_id', $seoCheckId)->whereNotNull('html_content')->count();

        $this->info("Total Results: {$totalResults}");
        $this->info("Results with HTML: {$resultsWithHtml}");

        $this->info("\nSample HTML Content:");
        SeoCrawlResult::where('seo_check_id', $seoCheckId)
            ->whereNotNull('html_content')
            ->take(3)
            ->get()
            ->each(function ($result) {
                $this->line("URL: {$result->url}");
                $this->line("HTML Size: " . strlen($result->html_content) . " bytes");
                $this->line("Title: " . ($result->meta_title ?: 'No title'));
                $this->line("Description: " . ($result->meta_description ?: 'No description'));
                $this->line("---");
            });

        $this->info("\nSEO Issues:");
        $errors = SeoIssue::where('seo_check_id', $seoCheckId)->where('severity', 'error')->count();
        $warnings = SeoIssue::where('seo_check_id', $seoCheckId)->where('severity', 'warning')->count();
        $notices = SeoIssue::where('seo_check_id', $seoCheckId)->where('severity', 'notice')->count();

        $this->info("Errors: {$errors}");
        $this->info("Warnings: {$warnings}");
        $this->info("Notices: {$notices}");

        if ($errors + $warnings + $notices > 0) {
            $this->info("\nSample Issues:");
            SeoIssue::where('seo_check_id', $seoCheckId)
                ->take(5)
                ->get()
                ->each(function ($issue) {
                    $this->line("{$issue->severity->value}: {$issue->title}");
                });
        }
    }
}
