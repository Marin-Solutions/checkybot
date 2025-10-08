<?php

namespace App\Console\Commands;

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Console\Command;

class RunTestSeoCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seo:run-test-check {websiteUrl?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a test SEO check on a website';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $websiteUrl = $this->argument('websiteUrl') ?? 'https://laravel.com';

        $website = Website::where('url', $websiteUrl)->first();

        if (! $website) {
            $this->error("Website not found: {$websiteUrl}");

            return Command::FAILURE;
        }

        $this->info("Running SEO check on: {$website->name} ({$website->url})");

        // Create SEO check
        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
        ]);

        $this->info("SEO Check created with ID: {$seoCheck->id}");

        // Dispatch the job
        SeoHealthCheckJob::dispatch($seoCheck);

        $this->info('SEO check job dispatched. You can monitor progress at:');
        $this->info("http://localhost/admin/seo-checks/{$seoCheck->id}");

        return Command::SUCCESS;
    }
}
