<?php

namespace App\Console\Commands;

use App\Jobs\SeoHealthCheckJob;
use App\Models\SeoCheck;
use App\Models\Website;
use Illuminate\Console\Command;

class StartNewSeoCheck extends Command
{
    protected $signature = 'seo:start-new {url}';
    protected $description = 'Start a new SEO check for a website';

    public function handle()
    {
        $url = $this->argument('url');

        $website = Website::where('url', $url)->first();
        if (!$website) {
            $this->error("Website with URL {$url} not found");
            return;
        }

        $seoCheck = SeoCheck::create([
            'website_id' => $website->id,
            'status' => 'pending',
            'started_at' => now(),
        ]);

        $this->info("Created SEO Check ID: {$seoCheck->id}");

        // Dispatch the job
        dispatch(new SeoHealthCheckJob($seoCheck));

        $this->info("SEO check job dispatched for {$url}");
    }
}

