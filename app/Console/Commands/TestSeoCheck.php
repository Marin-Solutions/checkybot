<?php

namespace App\Console\Commands;

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Console\Command;

class TestSeoCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:seo-check {websiteId}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test SEO check for a specific website';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $websiteId = $this->argument('websiteId');
        $website = Website::find($websiteId);

        if (!$website) {
            $this->error("Website with ID {$websiteId} not found");
            return Command::FAILURE;
        }

        $this->info("Testing SEO check for: {$website->url}");

        // Create SEO check
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        $this->info("Created SEO check with ID: {$seoCheck->id}");

        // Dispatch job
        SeoHealthCheckJob::dispatch($seoCheck);

        $this->info("SEO check job dispatched. Check the logs for progress.");

        return Command::SUCCESS;
    }
}
