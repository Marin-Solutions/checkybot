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
    public function handle(): int
    {
        $websites = Website::query()->where('outbound_check', 1)->get();

        $queuedAt = now();
        $dispatchedCount = 0;

        $websites->each(function (Website $website) use ($queuedAt, &$dispatchedCount): void {
            if ($website->hasQueuedOutboundScan()) {
                return;
            }

            $queuedStatePersisted = false;

            try {
                $website->forceFill([
                    'outbound_scan_queued_at' => $queuedAt,
                ])->save();
                $queuedStatePersisted = true;

                WebsiteCheckOutboundLinkJob::dispatch($website, WebsiteCheckOutboundLinkJob::SOURCE_SCHEDULED)->onQueue('log-website');
                $dispatchedCount++;
            } catch (\Throwable $e) {
                if ($queuedStatePersisted) {
                    $website->forceFill([
                        'outbound_scan_queued_at' => null,
                    ])->save();
                }

                Log::error('Scheduled outbound scan dispatch failed', [
                    'website_id' => $website->id,
                    'exception' => $e,
                ]);

                throw $e;
            }
        });

        Log::info('Scan completed and jobs dispatched for outbound link checks', ['website_count' => $dispatchedCount]);

        return Command::SUCCESS;
    }
}
