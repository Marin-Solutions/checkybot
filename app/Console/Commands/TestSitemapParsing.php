<?php

namespace App\Console\Commands;

use App\Services\RobotsSitemapService;
use Illuminate\Console\Command;

class TestSitemapParsing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:sitemap {url}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test sitemap parsing for a given URL';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $url = $this->argument('url');
        $service = app(RobotsSitemapService::class);

        $this->info("Testing sitemap parsing for: {$url}");

        // Test sitemap URLs
        $sitemapUrls = $service->getSitemapUrls($url);
        $this->info("Sitemap URLs found: " . count($sitemapUrls));

        if (!empty($sitemapUrls)) {
            $this->info("First 10 sitemap URLs:");
            foreach (array_slice($sitemapUrls, 0, 10) as $sitemapUrl) {
                $this->line("  - {$sitemapUrl}");
            }
        }

        // Test crawlable URLs
        $crawlableUrls = $service->getCrawlableUrls($url);
        $this->info("Crawlable URLs found: " . count($crawlableUrls));

        if (!empty($crawlableUrls)) {
            $this->info("First 10 crawlable URLs:");
            foreach (array_slice($crawlableUrls, 0, 10) as $crawlableUrl) {
                $this->line("  - {$crawlableUrl}");
            }
        }

        // Test robots.txt
        $isAllowed = $service->isUrlAllowed($url);
        $this->info("URL allowed by robots.txt: " . ($isAllowed ? 'Yes' : 'No'));

        return Command::SUCCESS;
    }
}
