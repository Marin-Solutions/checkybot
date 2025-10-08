<?php

namespace App\Console\Commands;

use App\Events\CrawlProgressUpdated;
use Illuminate\Console\Command;

class TestBroadcasting extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:broadcasting {seoCheckId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test broadcasting for SEO check progress updates';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $seoCheckId = $this->argument('seoCheckId');

        $this->info("Testing broadcast for SEO check ID: {$seoCheckId}");

        // Test broadcast
        broadcast(new CrawlProgressUpdated(
            seoCheckId: (int) $seoCheckId,
            urlsCrawled: 10,
            totalUrls: 100,
            issuesFound: 5,
            progress: 10,
            currentUrl: 'https://example.com/test',
            etaSeconds: 300
        ));

        $this->info('Broadcast sent successfully!');

        return Command::SUCCESS;
    }
}
