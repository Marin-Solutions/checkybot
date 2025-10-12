<?php

namespace App\Console\Commands;

use App\Jobs\WebsiteCheckOutboundLinkJob;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ScanWebsiteForOutboundCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'website:scan-outbound-check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan websites with Outbound check enabled and dispatch jobs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $websites = Website::query()->where('outbound_check', 1)->get();

        $websites->each(function ($website) {
            WebsiteCheckOutboundLinkJob::dispatch($website)->onQueue('log-website');
        });

        Log::info('Scan completed and jobs dispatched for SSL checks', ['website_count' => $websites->count()]);
    }
}
